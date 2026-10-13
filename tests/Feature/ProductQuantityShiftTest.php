<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\Admin\ProductController;
use App\Models\Employee;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProductQuantityShiftTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        Schema::create('employees', function ($table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->softDeletes();
        });
        Schema::create('daily_quantities', function ($table) {
            $table->id();
            $table->string('product_id');
            $table->string('employee_id');
            $table->integer('quantity');
            $table->integer('status');
            $table->string('shift')->nullable();
            $table->date('date');
            $table->timestamps();
        });
        foreach (['total_daily_quantities' => 'date', 'total_month_quantities' => 'month'] as $name => $period) {
            Schema::create($name, function ($table) use ($period) {
                $table->id();
                $table->string('product_id');
                $table->integer('status');
                $table->string($period);
                $table->integer('totalQuan');
                $table->timestamps();
            });
        }
        $employee = new Employee;
        $employee->id = 'admin-test';
        $employee->name = 'Admin';
        $employee->role_id = 1;
        $this->actingAs($employee);
        Route::put('/_test/product-quantity', [ProductController::class, 'addQuantityDetailProduct']);
    }

    public function test_production_requires_a_valid_shift_without_writing_rows(): void
    {
        foreach ([null, '', 'Ca 3'] as $shift) {
            $this->putJson('/_test/product-quantity', $this->payload(1, $shift))
                ->assertUnprocessable()->assertJsonValidationErrors('shift');
        }
        $this->assertSame(0, DB::table('daily_quantities')->count());
    }

    public function test_both_shifts_are_saved_and_totals_still_include_both(): void
    {
        foreach (['Ca 1', 'Ca 2'] as $shift) {
            $this->putJson('/_test/product-quantity', $this->payload(1, $shift))
                ->assertCreated()->assertJsonPath('daily.shift', $shift)
                ->assertJsonPath('daily.date', '2026-10-13');
        }
        $this->assertSame(['Ca 1', 'Ca 2'], DB::table('daily_quantities')->orderBy('id')->pluck('shift')->all());
        $this->assertSame(20, DB::table('total_daily_quantities')->value('totalQuan'));
        $this->assertSame(20, DB::table('total_month_quantities')->value('totalQuan'));
    }

    public function test_other_statuses_do_not_require_or_save_a_shift(): void
    {
        foreach ([2, 6] as $status) {
            foreach ([null, 'Ca 2'] as $shift) {
                $this->putJson('/_test/product-quantity', $this->payload($status, $shift))
                    ->assertCreated()->assertJsonPath('daily.shift', null);
            }
        }
        $this->assertSame(4, DB::table('daily_quantities')->whereNull('shift')->count());
    }

    private function payload(int $status, ?string $shift): array
    {
        return ['product_id' => 'product-test', 'date' => '2026-10-13', 'quantity' => 10, 'status' => $status, 'shift' => $shift];
    }
}
