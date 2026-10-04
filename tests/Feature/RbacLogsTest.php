<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\Admin\RBACController;
use App\Http\Middleware\ApiRequestContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Monolog\Handler\TestHandler;
use Monolog\Logger as Monolog;
use Tests\TestCase;

class RbacLogsTest extends TestCase
{
    public function test_creation_skips_soft_deleted_accounts_and_returns_string_id(): void
    {
        // Isolated database only: never migrate or write to the configured MySQL DB.
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('role_name');
            $table->softDeletes();
        });
        Schema::create('employees', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->string('phone')->unique();
            $table->string('password')->nullable();
            $table->integer('role_id');
            $table->integer('calendar_category_id')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
        DB::table('roles')->insert([
            ['id' => 1, 'role_name' => 'Admin'],
            ['id' => 2, 'role_name' => 'Co Admin'],
        ]);
        DB::table('employees')->insert([
            'id' => 'Admin9', 'name' => 'Deleted', 'phone' => 'ctyvinhvinhphat9',
            'role_id' => 1, 'deleted_at' => now(),
        ]);

        foreach (['admin' => 10, 'co-admin' => 11] as $role => $number) {
            $response = (new RBACController)->createAdminUser(Request::create(
                '/api/rbac/create-admin-user', 'POST', ['name' => 'Test', 'role' => $role]
            ));
            $this->assertSame(200, $response->getStatusCode(), $response->getContent());
            $this->assertSame('Admin'.$number, $response->getData(true)['admin_user']['id']);
            $this->assertSame($role === 'admin' ? 'Admin' : 'Co Admin', $response->getData(true)['admin_user']['role_name']);
            $this->assertTrue(DB::table('employees')->where('id', 'Admin'.$number)->exists());
        }
        $this->assertNotNull(DB::table('employees')->where('id', 'Admin9')->value('deleted_at'));
    }

    public function test_error_response_and_log_share_id_without_payload_or_query(): void
    {
        $records = new TestHandler;
        Log::swap(new Logger(new Monolog('testing', [$records])));
        $request = Request::create('/api/rbac/create-admin-user?token=secret', 'POST', ['password' => 'secret']);
        $request->headers->set('X-Request-ID', 'untrusted');
        $response = (new ApiRequestContext)->handle($request, function () {
            Log::error('RBAC create admin user error');

            return response()->json(['message' => 'Failed'], 500);
        });
        $id = $response->headers->get('X-Request-ID');
        $this->assertTrue(\Illuminate\Support\Str::isUuid($id));
        foreach ($records->getRecords() as $record) {
            $this->assertSame($id, $record['context']['request_id']);
            $this->assertStringNotContainsString('secret', json_encode($record['context']));
        }
        Log::info('After request');
        $last = $records->getRecords();
        $this->assertArrayNotHasKey('request_id', end($last)['context']);
    }

    public function test_validation_exception_has_request_id_and_warning_log(): void
    {
        $records = new TestHandler;
        Log::swap(new Logger(new Monolog('testing', [$records])));
        $request = Request::create('/api/rbac/create-admin-user', 'POST');
        $request->headers->set('Accept', 'application/json');
        $response = (new ApiRequestContext)->handle($request, function () {
            throw \Illuminate\Validation\ValidationException::withMessages(['name' => ['Required']]);
        });
        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(['Required'], $response->getData(true)['errors']['name']);
        $this->assertSame($response->headers->get('X-Request-ID'), $records->getRecords()[0]['context']['request_id']);
        $this->assertTrue($records->hasWarningRecords());
    }

    public function test_success_has_id_but_does_not_log_error(): void
    {
        $records = new TestHandler;
        Log::swap(new Logger(new Monolog('testing', [$records])));
        $response = (new ApiRequestContext)->handle(Request::create('/api/test'), fn () => response()->json(['ok' => true]));
        $this->assertNotEmpty($response->headers->get('X-Request-ID'));
        $this->assertSame([], $records->getRecords());
    }

    public function test_system_log_routes_require_super_admin(): void
    {
        foreach (['GET' => '/api/logs/system', 'DELETE' => '/api/logs/system/clear'] as $method => $url) {
            $route = Route::getRoutes()->match(Request::create($url, $method));
            $this->assertContains('api.permission', $route->gatherMiddleware());
        }
        $this->assertContains('X-Request-ID', config('cors.exposed_headers'));
    }
}
