<?php

namespace App\Services;

use Illuminate\Support\Facades\File;

class HolidaySchedule
{
    public const HOLIDAYS = ['new-year', 'april', 'mid-autumn', 'christmas'];

    private string $path;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? storage_path('app/private/holiday-schedule.json');
    }

    public function current(): array
    {
        if (! File::exists($this->path)) {
            return ['revision' => 0, 'schedules' => []];
        }

        return json_decode(File::get($this->path), true, 512, JSON_THROW_ON_ERROR);
    }

    public function save(array $schedules, int $revision): array
    {
        File::ensureDirectoryExists(dirname($this->path));
        $lock = fopen($this->path.'.lock', 'c');
        abort_if($lock === false, 503, 'Chưa mở được lịch giao diện. Vui lòng thử lại.');
        try {
            abort_unless(flock($lock, LOCK_EX), 503, 'Chưa khóa được lịch giao diện. Vui lòng thử lại.');
            $current = $this->current();
            abort_if($current['revision'] !== $revision, 409, 'Lịch đã được người khác cập nhật. Vui lòng tải lại trước khi lưu.');
            $next = ['revision' => $revision + 1, 'schedules' => $schedules];
            File::replace($this->path, json_encode($next, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), 0600);

            return $next;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
