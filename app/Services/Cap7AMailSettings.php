<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class Cap7AMailSettings
{
    public function current(): array
    {
        $row = DB::table('cap7a_mail_settings')->where('id', 1)->first();

        return [
            'enabled' => $row ? (bool) $row->enabled : (bool) config('cap7a.production_mail.enabled'),
            'recipient' => $row ? $row->recipient : config('cap7a.production_mail.recipient'),
            'tracking_since_ms' => $row?->tracking_since_ms === null ? null : (int) $row->tracking_since_ms,
        ];
    }

    public function save(bool $enabled, string $recipient): array
    {
        // Settings and delivery share a lock: a successful save cannot race an old recipient.
        $lock = Cache::lock('cap7a:production-mail', 600);
        abort_unless($lock->get(), 409, 'Đang xử lý báo cáo CAP7A. Vui lòng lưu lại sau.');
        try {
            return DB::transaction(function () use ($enabled, $recipient) {
                $current = $this->current();
                $since = $current['tracking_since_ms'];
                if ($enabled && (! $current['enabled'] || $since === null)) {
                    $since = now()->getTimestampMs();
                }
                DB::table('cap7a_mail_settings')->upsert([
                    'id' => 1, 'enabled' => $enabled, 'recipient' => $recipient,
                    'tracking_since_ms' => $since, 'created_at' => now(), 'updated_at' => now(),
                ], ['id'], ['enabled', 'recipient', 'tracking_since_ms', 'updated_at']);
                DB::table('cap7a_production_reports')->whereNull('sent_at')
                    ->update(['recipient' => $recipient, 'updated_at' => now()]);

                return $this->current();
            });
        } finally {
            $lock->release();
        }
    }
}
