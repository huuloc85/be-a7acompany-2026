<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\Admin\VvpPayrollProfileController;
use App\Support\VvpPayrollProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class VvpPayrollProfileTest extends TestCase
{
    private array $profile;

    public function test_bank_bin_is_saved_as_text_and_survives_reload(): void
    {
        $controller = new VvpPayrollProfileController;
        $controller->update($this->request(['profile' => [...$this->profile, 'bankBin' => '970422'], 'revision' => 1]), '001');
        $row = $controller->index($this->request())->getData(true)['data'][0];
        $this->assertSame('970422', $row['profile']['bankBin']);
        $this->assertSame('0000123', $row['profile']['bankAccount']);
        $this->expectException(ValidationException::class);
        VvpPayrollProfile::validate([...$this->profile, 'bankBin' => 'MB']);
    }

    public function test_create_list_delete_preserves_employee_and_uses_web_identity(): void
    {
        $controller = new VvpPayrollProfileController;
        $this->assertSame('web001', $controller->index($this->request())->getData(true)['availableEmployees'][0]['id']);
        $response = $controller->store($this->request([
            'profile' => [...$this->profile, 'employeeId' => 'web001', 'attendanceEmployeeId' => '001'],
            'effectiveFrom' => '2026-09-16',
        ]));
        $this->assertSame(201, $response->getStatusCode());
        $saved = $response->getData(true)['data'];
        $this->assertSame('web001', $saved['employee_id']);
        $this->assertSame('Linked employee name', $saved['profile']['name']);
        $this->assertSame('2026-09-16', $saved['effectiveFrom']);
        $this->assertSame([], $controller->index($this->request())->getData(true)['availableEmployees']);
        $controller->destroy($this->request(['revision' => 1]), 'web001');
        $this->assertTrue(DB::table('employees')->where('id', 'web001')->exists());
        $this->assertFalse(DB::table('payroll_profiles')->where('employee_code', 'web001')->exists());
    }

    public function test_create_rejects_duplicate_and_delete_rejects_stale_revision(): void
    {
        $controller = new VvpPayrollProfileController;
        foreach (['create', 'delete'] as $operation) {
            try {
                if ($operation === 'create') {
                    $controller->store($this->request(['profile' => $this->profile, 'effectiveFrom' => '2026-09-16']));
                } else {
                    $controller->destroy($this->request(['revision' => 2]), '001');
                }
                $this->fail('Expected conflict');
            } catch (HttpException $exception) {
                $this->assertSame(409, $exception->getStatusCode());
            }
        }
        $this->assertSame(1, DB::table('payroll_profiles')->count());
    }

    public function test_read_only_user_cannot_create_or_delete(): void
    {
        $controller = new VvpPayrollProfileController;
        foreach (['store', 'destroy'] as $method) {
            try {
                $controller->$method($this->request([], 'Employee', true), '001');
                $this->fail('Expected forbidden');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Never connect to or migrate the configured remote database.
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        DB::statement('PRAGMA foreign_keys = ON');
        \Illuminate\Support\Facades\Schema::create('employees', function ($table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->string('company');
            $table->timestamp('deleted_at')->nullable();
        });
        DB::table('employees')->insert([
            ['id' => '001', 'name' => 'Current employee name', 'company' => 'VVP'],
            ['id' => 'web001', 'name' => 'Linked employee name', 'company' => 'VVP'],
        ]);
        (require database_path('migrations/2026_10_06_000001_create_vvp_payroll_profiles_table.php'))->up();
        $this->profile = [
            'employeeId' => '001', 'name' => 'Test employee', 'department' => 'Test',
            'bankAccount' => '0000123', 'dependents' => 0, 'basicSalary' => 6000000,
            'responsibility' => 0, 'housingPerDay' => 0, 'travelPerDay' => 0,
            'mealPerDay' => 0, 'diligence' => 500000, 'insuranceBase' => 6000000,
            'unionBase' => 6000000, 'legacySalary26Days' => 5200000,
        ];
        DB::table('vvp_payroll_profiles')->insert([
            'employee_code' => '001', 'profile' => json_encode($this->profile),
            'revision' => 1, 'source' => 'Synthetic test', 'source_row' => 8,
            'effective_from' => '2026-08-16', 'created_at' => now(), 'updated_at' => now(),
        ]);
        (require database_path('migrations/2026_10_07_000001_link_vvp_payroll_profiles_to_employees.php'))->up();
        (require database_path('migrations/2026_10_06_000002_create_vvp_payroll_holidays_table.php'))->up();
        (require database_path('migrations/2026_10_08_000001_create_vvp_payroll_adjustments_table.php'))->up();
        (require database_path('migrations/2026_10_13_000002_rename_shared_payroll_tables.php'))->up();
    }

    private function request(array $payload = [], string $role = 'Admin', bool $permission = false): Request
    {
        $request = Request::create('/test', 'PUT', $payload);
        $user = new class($role, $permission)
        {
            public object $role;

            public function __construct(string $role, private bool $permission)
            {
                $this->role = (object) ['role_name' => $role];
            }

            public function hasPermission(string $permission): bool
            {
                return $permission === 'view_salary_total' && $this->permission;
            }

            public function getAuthIdentifier(): string
            {
                return 'test-admin';
            }
        };
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    public function test_save_and_reload_preserve_zero_values_and_identifiers(): void
    {
        $controller = new VvpPayrollProfileController;
        $profile = [...$this->profile, 'basicSalary' => 0, 'name' => 'Updated test'];
        $saved = $controller->update($this->request(['profile' => $profile, 'revision' => 1]), '001');
        $this->assertSame(2, $saved->getData(true)['data']['revision']);
        $response = $controller->index($this->request());
        $row = $response->getData(true)['data'][0];
        $this->assertSame('001', $row['profile']['employeeId']);
        $this->assertSame('0000123', $row['profile']['bankAccount']);
        $this->assertEquals(0, $row['profile']['basicSalary']);
        $this->assertSame('Current employee name', $row['profile']['name']);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame('test-admin', DB::table('payroll_profiles')->value('updated_by'));
    }

    public function test_stale_revision_cannot_overwrite_a_saved_profile(): void
    {
        $controller = new VvpPayrollProfileController;
        $request = $this->request(['profile' => $this->profile, 'revision' => 1]);
        $controller->update($request, '001');
        try {
            $controller->update($request, '001');
            $this->fail('Expected conflict');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
            $this->assertSame(2, DB::table('payroll_profiles')->value('revision'));
        }
    }

    public function test_read_permission_does_not_grant_edit_permission(): void
    {
        $controller = new VvpPayrollProfileController;
        $response = $controller->index($this->request([], 'Employee', true));
        $this->assertFalse($response->getData(true)['canEdit']);
        $this->expectException(HttpException::class);
        $controller->update($this->request(['profile' => $this->profile, 'revision' => 1], 'Employee', true), '001');
    }

    public function test_employee_without_permission_cannot_read_catalogue(): void
    {
        $this->assertFalse(VvpPayrollProfile::canRead(null));
        $this->expectException(HttpException::class);
        (new VvpPayrollProfileController)->index($this->request([], 'Employee'));
    }

    public function test_negative_salary_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        VvpPayrollProfile::validate([...$this->profile, 'basicSalary' => -1]);
    }

    public function test_employee_code_cannot_be_changed(): void
    {
        $this->expectException(ValidationException::class);
        (new VvpPayrollProfileController)->update($this->request([
            'profile' => [...$this->profile, 'employeeId' => '002'], 'revision' => 1,
        ]), '001');
    }

    public function test_routes_require_authenticated_non_expired_token(): void
    {
        foreach (['GET' => '/api/vvp-payroll-profiles', 'PUT' => '/api/vvp-payroll-profiles/001'] as $method => $url) {
            $route = Route::getRoutes()->match(Request::create($url, $method));
            $this->assertContains('auth:sanctum', $route->gatherMiddleware());
            $this->assertContains('check.token.expiration', $route->gatherMiddleware());
        }
    }

    public function test_mapping_is_persisted_without_changing_salary_or_source_code(): void
    {
        $controller = new VvpPayrollProfileController;
        $profile = [...$this->profile, 'attendanceEmployeeId' => 'web001'];
        $saved = $controller->update($this->request(['profile' => $profile, 'revision' => 1]), '001')->getData(true)['data'];
        $this->assertSame('web001', $saved['profile']['attendanceEmployeeId']);
        $this->assertSame('Linked employee name', $saved['profile']['name']);
        $this->assertSame('web001', $saved['employee_id']);
        $this->assertSame('001', $saved['profile']['employeeId']);
        $this->assertEquals($this->profile['basicSalary'], $saved['profile']['basicSalary']);
        // An older client omitting the optional field must not erase a saved mapping.
        $controller->update($this->request(['profile' => $this->profile, 'revision' => 2]), '001');
        $loaded = $controller->index($this->request())->getData(true)['data'][0]['profile'];
        $this->assertSame('web001', $loaded['attendanceEmployeeId']);
    }

    public function test_mapping_cannot_claim_another_profiles_web_id(): void
    {
        DB::table('payroll_profiles')->insert([
            'employee_code' => '002', 'profile' => json_encode([...$this->profile, 'employeeId' => '002', 'attendanceEmployeeId' => 'web001']),
            'revision' => 1, 'source' => 'Test', 'source_row' => 9, 'effective_from' => '2026-08-16',
        ]);
        $this->expectException(ValidationException::class);
        (new VvpPayrollProfileController)->update($this->request([
            'profile' => [...$this->profile, 'attendanceEmployeeId' => 'web001'], 'revision' => 1,
        ]), '001');
    }

    public function test_reads_live_name_without_rewriting_salary_profile(): void
    {
        $controller = new VvpPayrollProfileController;
        DB::table('employees')->where('id', '001')->update(['name' => 'Renamed on employees']);
        $row = $controller->index($this->request())->getData(true)['data'][0];
        $this->assertSame('Renamed on employees', $row['profile']['name']);
        $this->assertTrue($row['employeeLinked']);
        $this->assertSame('Test employee', json_decode(DB::table('payroll_profiles')->value('profile'), true)['name']);
        DB::table('employees')->where('id', '001')->update(['deleted_at' => now()]);
        $this->assertTrue($controller->index($this->request())->getData(true)['data'][0]['employeeInactive']);
    }

    public function test_employee_column_is_authoritative_over_old_json_mapping(): void
    {
        $this->assertSame('001', DB::table('payroll_profiles')->value('employee_id'));
        $profile = [...$this->profile, 'attendanceEmployeeId' => 'web001'];
        DB::table('payroll_profiles')->update(['profile' => json_encode($profile)]);
        $row = (new VvpPayrollProfileController)->index($this->request())->getData(true)['data'][0];
        $this->assertSame('001', $row['profile']['attendanceEmployeeId']);
        $this->assertSame('Current employee name', $row['profile']['name']);
    }

    public function test_missing_employee_is_visible_and_invalid_mapping_is_rejected(): void
    {
        DB::table('payroll_profiles')->where('employee_code', '001')->update(['employee_id' => null]);
        DB::table('employees')->where('id', '001')->delete();
        $controller = new VvpPayrollProfileController;
        $row = $controller->index($this->request())->getData(true)['data'][0];
        $this->assertFalse($row['employeeLinked']);
        $this->assertSame('Test employee', $row['profile']['name']);
        $this->expectException(ValidationException::class);
        $controller->update($this->request(['profile' => $this->profile, 'revision' => 1]), '001');
    }
}
