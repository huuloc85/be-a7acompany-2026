<?php

namespace App\Console\Commands;

use App\Services\Cap7ADailyStarts;
use App\Services\Cap7ASystem;
use App\Services\Cap7AThingsBoard;
use Illuminate\Console\Command;
use Throwable;

class CollectCap7ADailyStarts extends Command
{
    protected $signature = 'cap7a:collect-daily-starts';

    protected $description = 'Lưu giờ bắt đầu từ 07:00 của từng camera CAP7A.';

    public function handle(): int
    {
        $failed = false;
        foreach (['tail', 'spike'] as $id) {
            try {
                $system = new Cap7ASystem($id);
                $source = app()->makeWith(Cap7AThingsBoard::class, ['system' => $system]);
                if (! $source->configured()) {
                    continue;
                }
                $snapshot = $source->status();
                (new Cap7ADailyStarts)->record($system, $snapshot['state']['app_started_at_ms'] ?? null);
            } catch (Throwable $error) {
                report($error);
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
