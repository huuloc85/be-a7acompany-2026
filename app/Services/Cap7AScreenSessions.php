<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\PersonalAccessToken;

class Cap7AScreenSessions
{
    public function __construct(public readonly Cap7ASystem $system) {}

    public function enabled(): bool
    {
        return $this->system->config('screen_enabled')
            && ($this->system->config('screen_dynamic_url')
                || preg_match('~^wss://[^?#@]+$~', $this->system->config('screen_ws_url') ?? ''))
            && strlen($this->system->config('screen_gateway_key') ?? '') >= 32;
    }

    public function issue($user): array
    {
        abort_unless($this->enabled(), 503, 'Chưa cấu hình gateway màn hình.');
        $url = $this->system->config('screen_dynamic_url')
            ? Cache::get($this->endpointCacheKey()) : $this->system->config('screen_ws_url');
        abort_unless($url, 503, 'Gateway chưa sẵn sàng; mở ứng dụng Cap7A trên Jetson và đợi tunnel kết nối.');
        $token = $user->currentAccessToken();
        abort_unless($token instanceof PersonalAccessToken, 403, 'Cần phiên đăng nhập bằng token.');
        $ticket = bin2hex(random_bytes(32));
        $hash = hash('sha256', $ticket);
        $actor = (string) $user->getAuthIdentifier();
        $actorLock = Cache::lock($this->system->cachePrefix().'screen:user-lock:'.$actor, 5);
        abort_unless($actorLock->get(), 409);
        try {
            Cache::put($this->system->cachePrefix().'screen:'.$hash, [
                'token_id' => $token->id, 'actor' => $actor, 'issued_at' => time(),
                'expires_at' => time() + 300, 'lease' => null,
            ], 300);
            // Issuing a new ticket revokes the previous viewer for this user.
            Cache::put($this->system->cachePrefix().'screen:user:'.$actor, $hash, 300);
        } finally {
            $actorLock->release();
        }

        return ['ticket' => $ticket, 'websocket_url' => $url,
            'connect_within_seconds' => 30, 'max_duration_seconds' => 300, 'view_only' => true];
    }

    public function authorize(string $ticket, string $lease, bool $opening): array
    {
        abort_unless($this->enabled(), 403);
        $hash = hash('sha256', $ticket);
        $key = $this->system->cachePrefix().'screen:'.$hash;
        $lock = Cache::lock($key.':lock', 5);
        abort_unless($lock->get(), 409);
        $actorLock = null;
        try {
            $record = Cache::get($key);
            abort_unless(is_array($record) && $record['expires_at'] > time(), 403);
            $candidateLock = Cache::lock($this->system->cachePrefix().'screen:user-lock:'.$record['actor'], 5);
            abort_unless($candidateLock->get(), 409);
            $actorLock = $candidateLock;
            abort_unless(Cache::get($this->system->cachePrefix().'screen:user:'.$record['actor']) === $hash, 403);
            if ($opening) {
                abort_unless($record['lease'] === null && time() - $record['issued_at'] <= 30, 403);
            } else {
                abort_unless($record['lease'] === $lease, 403);
            }
            $token = PersonalAccessToken::find($record['token_id']);
            $user = $token?->tokenable;
            abort_unless($user && (string) $user->getAuthIdentifier() === $record['actor']
                && strtolower(trim($user->role?->role_name ?? '')) === 'super admin'
                && (! $token->expires_at || $token->expires_at->isFuture()), 403);
            $expiration = config('sanctum.expiration');
            abort_if($expiration && $token->created_at->addMinutes($expiration)->isPast(), 403);
            $record['lease'] ??= $lease;
            // Sliding lease: the gateway must re-authorize periodically.
            $record['expires_at'] = time() + 300;
            Cache::put($key, $record, 300);
            Cache::put($this->system->cachePrefix().'screen:user:'.$record['actor'], $hash, 300);

            return ['allowed' => true, 'remaining_seconds' => 300, 'view_only' => true];
        } finally {
            $actorLock?->release();
            $lock->release();
        }
    }

    public function endpointCacheKey(): string
    {
        return $this->system->cachePrefix().'screen:endpoint:'.hash('sha256', (string) $this->system->config('screen_gateway_key'));
    }
}
