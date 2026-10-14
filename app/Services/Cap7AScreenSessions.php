<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\PersonalAccessToken;

class Cap7AScreenSessions
{
    public function enabled(): bool
    {
        return config('cap7a.screen_enabled')
            && preg_match('~^wss://[^?#@]+$~', config('cap7a.screen_ws_url') ?? '')
            && strlen(config('cap7a.screen_gateway_key') ?? '') >= 32;
    }

    public function issue($user): array
    {
        abort_unless($this->enabled(), 503, 'Chưa cấu hình gateway màn hình.');
        $token = $user->currentAccessToken();
        abort_unless($token instanceof PersonalAccessToken, 403, 'Cần phiên đăng nhập bằng token.');
        $ticket = bin2hex(random_bytes(32));
        $hash = hash('sha256', $ticket);
        $actor = (string) $user->getAuthIdentifier();
        Cache::put('cap7a:screen:'.$hash, [
            'token_id' => $token->id, 'actor' => $actor, 'issued_at' => time(),
            'expires_at' => time() + 300, 'lease' => null,
        ], 300);
        // Issuing a new ticket revokes the previous viewer for this user.
        Cache::put('cap7a:screen:user:'.$actor, $hash, 300);

        return ['ticket' => $ticket, 'websocket_url' => config('cap7a.screen_ws_url'),
            'connect_within_seconds' => 30, 'max_duration_seconds' => 300, 'view_only' => true];
    }

    public function authorize(string $ticket, string $lease, bool $opening): array
    {
        abort_unless($this->enabled(), 403);
        $hash = hash('sha256', $ticket);
        $key = 'cap7a:screen:'.$hash;
        $lock = Cache::lock($key.':lock', 5);
        abort_unless($lock->get(), 409);
        try {
            $record = Cache::get($key);
            abort_unless(is_array($record) && $record['expires_at'] > time(), 403);
            abort_unless(Cache::get('cap7a:screen:user:'.$record['actor']) === $hash, 403);
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
            if ($opening) {
                $record['lease'] = $lease;
                Cache::put($key, $record, max(1, $record['expires_at'] - time()));
            }

            return ['allowed' => true, 'remaining_seconds' => $record['expires_at'] - time(), 'view_only' => true];
        } finally {
            $lock->release();
        }
    }
}
