<?php

namespace App\Console\Commands;

use App\Mail\Cap7AProductionReport;
use App\Services\Cap7AMailRecipients;
use App\Services\Cap7AMailSettings;
use App\Services\Cap7ASystem;
use App\Services\Cap7AThingsBoard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

class SendCap7AProductionReports extends Command
{
    protected $signature = 'cap7a:send-production-reports';

    protected $description = 'Gửi báo cáo sản lượng CAP7A khi thoát ứng dụng sau ít nhất 10 phút.';

    public function handle(): int
    {
        $lock = Cache::lock('cap7a:production-mail', 600);
        if (! $lock->get()) {
            return self::SUCCESS;
        }
        $failed = false;
        try {
            $settings = (new Cap7AMailSettings)->current();
            if (! $settings['enabled']) {
                return self::SUCCESS;
            }
            if (in_array(config('mail.mailers.'.config('mail.default').'.transport'), ['log', 'array', 'failover'], true)) {
                $this->error('Cần cấu hình mailer gửi thư thật cho báo cáo CAP7A.');

                return self::FAILURE;
            }
            $recipient = $settings['recipient'];
            try {
                Cap7AMailRecipients::parse($recipient);
            } catch (\Illuminate\Validation\ValidationException $error) {
                $this->error('Email nhận báo cáo CAP7A không hợp lệ.');

                return self::FAILURE;
            }
            foreach (['tail', 'spike'] as $id) {
                try {
                    $this->collect(new Cap7ASystem($id), $recipient, $settings['tracking_since_ms']);
                } catch (Throwable $error) {
                    report($error);
                    $failed = true;
                }
            }
            foreach (DB::table('cap7a_production_reports')->whereNull('sent_at')->orderBy('id')->limit(100)->get() as $row) {
                try {
                    Mail::bcc(Cap7AMailRecipients::parse($row->recipient))->send(new Cap7AProductionReport($row->system, json_decode($row->state, true, 512, JSON_THROW_ON_ERROR)));
                    DB::table('cap7a_production_reports')->where('id', $row->id)->update(['sent_at' => now(), 'updated_at' => now()]);
                } catch (Throwable $error) {
                    report($error);
                    $failed = true;
                }
            }
        } finally {
            $lock->release();
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function collect(Cap7ASystem $system, string $recipient, ?int $since): void
    {
        $client = new Cap7AThingsBoard($system);
        if (! $client->configured()) {
            return;
        }
        $cursorKey = $system->cachePrefix().'production-mail-cursor';
        // First activation starts now, rather than mailing historical sessions.
        $end = now()->getTimestampMs();
        $checkpoint = Cache::get($cursorKey);
        if ($checkpoint === null || ($since !== null && $checkpoint['since'] < $since)) {
            $checkpoint = ['since' => $since ?? $end, 'cursor' => $since ?? $end];
            Cache::forever($cursorKey, $checkpoint);
        }
        // Re-read one minute to include slightly delayed telemetry; DB key deduplicates.
        $start = max($checkpoint['since'], $checkpoint['cursor'] - 60000);
        for ($page = 0; $page < 10 && $start <= $end; $page++) {
            $records = $client->reportStateRecords($start, $end);
            $last = $start - 1;
            foreach ($records as $record) {
                $ts = $record['ts'] ?? null;
                if (! is_int($ts) || $ts < $start || $ts > $end || $ts < $last) {
                    throw new \RuntimeException('Thứ tự dữ liệu CAP7A không hợp lệ.');
                }
                $last = $ts;
                $state = $record['value'] ?? null;
                $state = is_string($state) ? json_decode($state, true) : $state;
                if (! $this->eligible($state, $end) || $state['app_exited_at_ms'] < $checkpoint['since']) {
                    continue;
                }
                $key = hash('sha256', $system->cachePrefix().$state['session_id'].':'.$state['app_started_at_ms']);
                DB::table('cap7a_production_reports')->insertOrIgnore([
                    'report_key' => $key, 'system' => $system->id, 'recipient' => $recipient,
                    'state' => json_encode($state, JSON_THROW_ON_ERROR),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            Cache::forever($cursorKey, ['since' => $checkpoint['since'], 'cursor' => count($records) < 1000 ? $end : $last + 1]);
            if (count($records) < 1000) {
                break;
            }
            $start = $last + 1;
        }
    }

    private function eligible(mixed $state, int $now): bool
    {
        if (! is_array($state) || ($state['schema'] ?? null) !== 1
            || ! is_string($state['session_id'] ?? null) || ! Str::isUuid($state['session_id'])) {
            return false;
        }
        $start = $state['app_started_at_ms'] ?? null;
        $exit = $state['app_exited_at_ms'] ?? null;
        if (! is_int($start) || ! is_int($exit) || $start <= 0 || $exit > $now || $exit - $start < 600000) {
            return false;
        }
        foreach (['total', 'ok', 'ng'] as $key) {
            if (! is_int($state[$key] ?? null) || $state[$key] < 0) {
                return false;
            }
        }

        return true;
    }
}
