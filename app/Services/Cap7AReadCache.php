<?php

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

// Display GETs only. Command guards must continue reading directly from the device service.
class Cap7AReadCache
{
    public function remember(Cap7ASystem $system, string $resource, int $seconds, Closure $read): array
    {
        if ($system->config('transport') !== 'iot') {
            return $read();
        }
        $base = $this->key($system, $resource);
        $key = $base.':'.Cache::get($base.':generation', 'initial');
        if (($cached = Cache::get($key)) !== null) {
            return $cached;
        }
        // Two sequential upstream reads can take 16s. Do not let waiters fill PHP workers.
        $lock = Cache::lock($key.':lock', 25);
        if (! $lock->get()) {
            throw new HttpException(503, 'Dữ liệu IoT đang được đồng bộ; vui lòng chờ.', null, ['Retry-After' => '1']);
        }
        try {
            return Cache::remember($key, $seconds, $read);
        } finally {
            $lock->release();
        }
    }

    public function invalidate(Cap7ASystem $system, string $resource): void
    {
        if ($system->config('transport') === 'iot') {
            // A read started before reset must not repopulate the current generation.
            Cache::put($this->key($system, $resource).':generation', (string) Str::uuid(), 60);
        }
    }

    private function key(Cap7ASystem $system, string $resource): string
    {
        return $system->cachePrefix().'display:'.hash('sha256', json_encode([
            config('cap7a.systems.'.$system->id), config('iot'), $resource,
        ], JSON_THROW_ON_ERROR));
    }
}
