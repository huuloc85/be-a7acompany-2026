<?php

namespace App\Support;

class PayrollExcelValues
{
    /** Validate imported snapshots against saved source cells, without recalculation. */
    public static function validate(array $draft): void
    {
        abort_unless(empty($draft['issues']) && empty($draft['reconciliation']['pending']), 422, 'Còn dòng Excel chưa đối chiếu.');
        $totals = [];
        $sources = [];
        foreach ($draft['rows'] as $row) {
            $source = $row['source'] ?? [];
            $r = $source['row'] ?? 0;
            $company = mb_strtolower(trim($row['profile']['company'] ?? ''));
            $company = $company === 'vinh vinh phát' ? 'vvp' : $company;
            abort_unless(in_array($company, ['a7a', 'vvp'], true) && is_int($r) && $r >= 8
                && ($source['sheet'] ?? '') === 'Tính lương' && ! empty($source['file']), 422, 'Thiếu thông tin nguồn Excel.');
            $key = $company.':'.$r;
            abort_if(isset($sources[$key]), 422, 'Trùng dòng nguồn Excel.');
            $sources[$key] = true;
            $a7a = $company === 'a7a';
            $normalize = fn ($name) => mb_strtolower(preg_replace('/\s+/u', ' ', trim($name)));
            abort_unless($normalize($source['cells']['C'.$r]['value'] ?? '') === $normalize($row['profile']['name']), 422, 'Tên không khớp dòng Excel.');
            $columns = ['dayDays' => $a7a ? 'F' : 'E', 'nightDays' => $a7a ? 'H' : 'G',
                'daySalary' => $a7a ? 'G' : 'F', 'nightSalary' => $a7a ? 'I' : 'H',
                'dayOvertimeSalary' => $a7a ? 'K' : 'J', 'nightOvertimeSalary' => $a7a ? 'M' : 'L',
                'reinforcementSalary' => $a7a ? 'O' : 'N', 'annualLeaveDays' => $a7a ? 'P' : 'O',
                'allowanceDays' => 'Q', 'housing' => 'R', 'travel' => 'S', 'meal' => 'T',
                'downtimeSalary' => 'V', 'holidaySalary' => 'X', 'paidLeaveSalary' => 'Z',
                'diligence' => 'AD', 'menstrualSalary' => 'AF', 'gross' => 'AI', 'union' => 'AJ',
                'social' => 'AK', 'health' => 'AL', 'unemployment' => 'AM', 'personalDeduction' => 'AN',
                'dependentDeduction' => 'AO', 'taxable' => 'AQ', 'tax' => 'AR', 'net' => 'AS',
                'employeeInsurance' => 'AT', 'employerInsurance' => 'AU', 'employerUnion' => 'AV'];
            if (! $a7a) {
                $columns['annualLeaveSalary'] = 'P';
            }
            foreach ($columns as $field => $column) {
                self::same($row[$field] ?? null, self::cell($source, $column.$r));
            }
            foreach (['downtimeDays' => 'U', 'holidayDays' => 'W', 'paidLeaveDays' => 'Y',
                'authorizedLeaveDays' => 'AB', 'unauthorizedLeaveDays' => 'AC', 'menstrualHours' => 'AE',
                'bonus' => 'AG', 'referral' => 'AH', 'taxExemptIncome' => 'AP'] as $field => $column) {
                self::same($row['adjustments'][$field] ?? null, self::cell($source, $column.$r));
            }
            foreach (['otherIncome', 'advance', 'otherDeduction'] as $field) {
                self::same($row['adjustments'][$field] ?? null, 0);
            }
            $netSource = $source['cells']['AS'.$r]['value'] ?? null;
            abort_unless(($row['excelNetBlank'] ?? false) === ($netSource === null), 422, 'Trạng thái ô thực lãnh không khớp Excel.');
            if (isset($row['cashSource'])) {
                $cash = $row['cashSource'];
                abort_unless(($cash['sheet'] ?? '') === 'TĂNG CƯỜNG TIỀN MẶT', 422, 'Nguồn tăng cường không hợp lệ.');
                $amount = self::cell($cash, 'V'.($cash['row'] ?? 0));
                abort_unless($normalize($cash['cells']['C'.$cash['row']]['value'] ?? '') === $normalize($row['profile']['name']), 422, 'Khoản tăng cường không khớp nhân viên.');
                self::same($row['cashTotal'] ?? null, $amount);
                self::same($row['cashReinforcementSalary'] ?? null, $amount);
            } else {
                self::same($row['cashTotal'] ?? 0, 0);
            }
            $totals[$company] = ($totals[$company] ?? 0) + $row['net'];
        }
        $expected = $draft['reconciliation']['sourceTotals'] ?? [];
        abort_unless(count($totals) === count($expected), 422, 'Thiếu tổng đối chiếu Excel.');
        foreach ($totals as $company => $total) {
            self::same($total, $expected[$company] ?? null);
        }
    }

    private static function cell(array $source, string $address): mixed
    {
        abort_unless(isset($source['cells'][$address]) && array_key_exists('value', $source['cells'][$address]), 422, 'Thiếu ô nguồn Excel: '.$address);

        return $source['cells'][$address]['value'] ?? 0;
    }

    private static function same(mixed $actual, mixed $expected): void
    {
        abort_unless(is_numeric($actual) && is_numeric($expected)
            && is_finite((float) $actual) && is_finite((float) $expected)
            && abs((float) $actual - (float) $expected) < 0.001, 422, 'Số liệu nhập không khớp ô nguồn Excel.');
    }
}
