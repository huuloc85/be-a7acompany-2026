<?php

namespace Tests\Unit;

use App\Support\AttendanceEmployeeCode;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class AttendanceEmployeeCodeTest extends TestCase
{
    public function test_all_legacy_codes_resolve_and_new_codes_are_unchanged(): void
    {
        $map = (new ReflectionClass(AttendanceEmployeeCode::class))->getConstant('LEGACY_CODES');
        self::assertCount(58, $map);
        self::assertCount(58, array_unique($map));
        foreach ($map as $old => $new) {
            self::assertSame($new, AttendanceEmployeeCode::resolve((string) $old));
            self::assertSame($new, AttendanceEmployeeCode::resolve($new));
        }
    }

    public function test_toan_and_hieu_are_not_mixed(): void
    {
        self::assertSame('24061901', AttendanceEmployeeCode::resolve('24021900'));
        self::assertSame('24101900', AttendanceEmployeeCode::resolve('24061900'));
        self::assertSame('24061901', AttendanceEmployeeCode::resolve('24061901'));
    }

    public function test_other_codes_and_whitespace(): void
    {
        self::assertSame('26091801', AttendanceEmployeeCode::resolve('26091801'));
        self::assertSame('26052800', AttendanceEmployeeCode::resolve("26022800\t"));
        self::assertSame('', AttendanceEmployeeCode::resolve(' '));
    }
}
