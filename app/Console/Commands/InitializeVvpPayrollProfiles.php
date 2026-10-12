<?php

namespace App\Console\Commands;

use App\Support\VvpPayrollProfile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class InitializeVvpPayrollProfiles extends Command
{
    protected $signature = 'payroll:initialize-vvp {--apply : Create the prepared catalogue in the configured database}';

    protected $description = 'One-time VVP catalogue setup from the private prepared seed (not a web Excel upload).';

    public function handle(): int
    {
        $path = storage_path('app/payroll/vvp-profiles-2026-09.json');
        if (! is_file($path)) {
            $this->error('Private catalogue seed is missing. Provision it outside public storage.');

            return self::FAILURE;
        }
        $seed = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        Validator::make($seed, [
            'source' => 'required|string|max:255',
            'sourceSha256' => 'required|string|size:64',
            'effectiveFrom' => 'required|date_format:Y-m-d',
            'profiles' => 'required|array|size:50',
            'profiles.*.sourceRow' => 'required|integer|min:8',
            'profiles.*.profile.employeeId' => 'required|string|distinct',
        ])->validate();
        $rows = [];
        foreach ($seed['profiles'] as $item) {
            $profile = VvpPayrollProfile::validate($item['profile']);
            $rows[] = [
                'employee_code' => $profile['employeeId'],
                'profile' => json_encode($profile, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'source' => $seed['source'], 'source_row' => $item['sourceRow'],
                'effective_from' => $seed['effectiveFrom'], 'revision' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ];
        }
        if (! $this->option('apply')) {
            $this->info('Validated 50 profiles. No database writes. Use --apply only on the intended database after migration.');

            return self::SUCCESS;
        }
        // Never reset edits or replace an existing catalogue on re-deployment.
        if (DB::table('payroll_profiles')->exists()) {
            $this->error('Catalogue already exists. Refusing to overwrite it.');

            return self::FAILURE;
        }
        DB::transaction(fn () => DB::table('payroll_profiles')->insert($rows));
        $this->info('Created 50 VVP profiles. Existing salary and employee records were not modified.');

        return self::SUCCESS;
    }
}
