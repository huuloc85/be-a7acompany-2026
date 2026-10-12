<?php

namespace App\Support;

use Illuminate\Support\Facades\Validator;

class VvpPayrollProfile
{
    public static function validate(array $profile): array
    {
        $rules = [
            'employeeId' => ['required', 'string', 'max:50', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'attendanceEmployeeId' => ['sometimes', 'nullable', 'string', 'max:50', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'company' => ['sometimes', 'nullable', 'string', 'max:100'],
            'name' => 'required|string|max:255',
            'department' => 'present|nullable|string|max:255',
            'bankAccount' => 'present|nullable|string|max:100',
            'bankBin' => ['sometimes', 'nullable', 'string', 'regex:/^\d{6}$/'],
            'dependents' => 'required|integer|min:0|max:100',
        ];
        foreach (['basicSalary', 'responsibility', 'housingPerDay', 'travelPerDay', 'mealPerDay',
            'diligence', 'insuranceBase', 'unionBase', 'legacySalary26Days'] as $field) {
            $rules[$field] = 'required|numeric|min:0|max:1000000000000';
        }
        $data = Validator::make($profile, $rules)->validate();
        foreach ($data as $key => $value) {
            if (in_array($key, ['employeeId', 'attendanceEmployeeId', 'name', 'department', 'bankAccount', 'company', 'bankBin'], true)) {
                $data[$key] = trim((string) $value);
            } else {
                $data[$key] = $key === 'dependents' ? (int) $value : (float) $value;
            }
        }

        return $data;
    }

    public static function canEdit($user): bool
    {
        return $user && in_array(strtolower(trim($user->role?->role_name ?? '')),
            ['super admin', 'admin', 'co admin'], true);
    }

    public static function canRead($user): bool
    {
        return $user && (self::canEdit($user) || $user->hasPermission('view_salary_total'));
    }
}
