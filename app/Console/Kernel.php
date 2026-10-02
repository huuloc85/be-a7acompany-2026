<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // $schedule->command('send-mail-daily-quantity:cron')->dailyAt(1, '08:00')->withoutOverlapping();
        // $schedule->command('update-stock-quantity:cron')->monthlyOn(1, '08:00')->withoutOverlapping();
        // $schedule->command('sendstamp:delete-rejected')->ever()->withoutOverlapping();
        $schedule->command('attendance:sync')->everyMinute()->withoutOverlapping();
        // Employee provisioning is confirmed on web, then handled by the LAN bridge.

        // Tự động xoá vĩnh viễn nhân viên trong thùng rác > 30 ngày
        $schedule->command('employees:cleanup-trash --days=30')
            ->dailyAt('02:00')
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/cleanup-trash.log'));
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
