<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\Admin\AttendanceCalculationController;
use App\Http\Controllers\Api\Admin\AttendanceHistoryController;
use App\Http\Controllers\Api\Employee\EmpAttendanceController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class SeptemberAttendanceTest extends TestCase
{
    public static function attendanceCases(): iterable
    {
        $cases = [
            'day' => ['2026-09-01', 'N', '1', '07:30', '17:30', 0, 9],
            'day category 2' => ['2026-09-01', 'N', '2', '07:30', '17:30', 0, 9],
            'night' => ['2026-09-01', 'D', '1', '21:30', '07:30', 1, 9],
            'night category 2' => ['2026-09-01', 'D', '2', '21:30', '07:30', 1, 9],
            'administrative' => ['2026-09-01', 'N', '4', '07:30', '17:00', 0, 8],
            'early day arrival' => ['2026-09-01', 'N', '1', '07:00', '17:30', 0, 9],
            'early night arrival' => ['2026-09-01', 'D', '1', '19:30', '07:30', 1, 9],
            'late arrival' => ['2026-09-01', 'N', '1', '08:00', '17:30', 0, 8.5],
            'early departure' => ['2026-09-01', 'N', '1', '07:30', '16:30', 0, 8],
            'leave during lunch' => ['2026-09-01', 'N', '1', '07:30', '12:00', 0, 4],
            'arrive during lunch' => ['2026-09-01', 'N', '1', '12:00', '17:30', 0, 5],
            'only lunch' => ['2026-09-01', 'N', '1', '11:45', '12:15', 0, 0],
            'night partial lunch' => ['2026-09-01', 'D', '1', '21:30', '02:00', 1, 4],
            'paid day break' => ['2026-09-01', 'N', '1', '09:30', '09:40', 0, 0],
            'admin break overlap' => ['2026-09-01', 'N', '4', '09:45', '10:15', 0, 0.25],
            'admin morning' => ['2026-09-01', 'N', '4', '07:30', '09:40', 0, 2],
            'round down quarter hour' => ['2026-09-01', 'N', '1', '07:31', '17:30', 0, 8.75],
            'additional overtime preserved' => ['2026-09-01', 'N', '1', '07:30', '18:30', 0, 10],
            'missing checkout' => ['2026-09-01', 'D', '1', '21:30', null, 1, 0],
            'legacy day' => ['2026-08-31', 'N', '1', '07:30', '17:30', 0, 9],
            'legacy category 2' => ['2026-08-31', 'N', '2', '07:30', '17:30', 0, 9],
            'legacy administrative' => ['2026-08-31', 'N', '4', '07:30', '17:00', 0, 8],
            'legacy night across cutoff' => ['2026-08-31', 'D', '1', '19:30', '07:30', 1, 11],
            'legacy lunch departure unchanged' => ['2026-08-31', 'N', '1', '07:30', '11:40', 0, 4],
        ];

        foreach ([AttendanceHistoryController::class, AttendanceCalculationController::class, EmpAttendanceController::class] as $controller) {
            foreach ($cases as $label => $case) {
                yield $controller.' '.$label => [$controller, ...$case];
            }
        }
    }

    #[DataProvider('attendanceCases')]
    public function test_calculated_hours($controller, $date, $schedule, $category, $in, $out, $nextDay, $expected): void
    {
        $dates = [$this->punch($date, $in)];
        if ($out !== null) {
            $outDate = (new \DateTimeImmutable($date))->modify("+$nextDay day")->format('Y-m-d');
            $dates[] = $this->punch($outDate, $out);
        }
        $attendance = [
            'employee_id' => 'test', 'name' => 'Test', 'company' => 'Test',
            'date' => $date, 'hnhc' => $schedule, 'calendar_category_id' => $category,
            'dates' => $dates,
        ];
        $class = new ReflectionClass($controller);
        $result = $class->getMethod('calculateAttendances')->invoke($class->newInstanceWithoutConstructor(), [$attendance]);

        self::assertCount(1, $result);
        self::assertEquals($expected, $result[0]['total_hours']);
        self::assertEquals(min(8, $expected), $result[0]['administrative_hours']);
        self::assertEquals(max(0, $expected - 8), $result[0]['overtime_hours']);
    }

    private function punch(string $date, string $time): array
    {
        return ['date' => $date, 'time' => $time.':00', 'datetime' => $date.' '.$time.':00'];
    }
}
