<?php

namespace Tests\Unit;

use App\Services\AttendanceDeviceDeleteService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\TestCase;

class AttendanceDeviceDeleteServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $app = new Container;
        Container::setInstance($app);
        Facade::setFacadeApplication($app);
        $app->instance('config', new Repository(['acs' => ['device_ip' => 'device.test', 'username' => 'test', 'password' => 'test']]));
        Http::swap(new Factory);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
        parent::tearDown();
    }

    private function profile(string $name = 'Example'): array
    {
        return ['UserInfoSearch' => ['totalMatches' => 1, 'UserInfo' => [['employeeNo' => '00123', 'name' => $name]]]];
    }

    public function test_deletes_exact_id_only_after_name_check_and_verifies_absence(): void
    {
        Http::fakeSequence()->push($this->profile())->push(['statusCode' => 1])
            ->push(['UserInfoSearch' => ['totalMatches' => 0]]);
        (new AttendanceDeviceDeleteService)->delete('00123', 'Example');
        Http::assertSentCount(3);
        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && $request->url() === 'http://device.test/ISAPI/AccessControl/UserInfo/Delete?format=json'
            && $request->data() === ['UserInfoDelCond' => ['EmployeeNoList' => [['employeeNo' => '00123']]]]);
    }

    public function test_absent_user_is_success_without_delete_call(): void
    {
        Http::fakeSequence()->push(['UserInfoSearch' => ['totalMatches' => 0]]);
        (new AttendanceDeviceDeleteService)->delete('00123', 'Example');
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => $request->method() === 'PUT');
    }

    public function test_name_conflict_never_sends_delete(): void
    {
        Http::fakeSequence()->push($this->profile('Another person'));
        try {
            (new AttendanceDeviceDeleteService)->delete('00123', 'Example');
            self::fail('Must refuse mismatched identity');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('tên khác', $e->getMessage());
            Http::assertSentCount(1);
        }
    }

    public function test_malformed_search_is_not_treated_as_absent(): void
    {
        Http::fakeSequence()->push(['UserInfoSearch' => []]);
        $this->expectException(\RuntimeException::class);
        (new AttendanceDeviceDeleteService)->delete('00123', 'Example');
    }

    public function test_http_200_device_rejection_is_not_success(): void
    {
        Http::fakeSequence()->push($this->profile())->push(['statusCode' => 4]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('từ chối');
        (new AttendanceDeviceDeleteService)->delete('00123', 'Example');
    }

    public function test_profile_still_present_after_delete_is_not_success(): void
    {
        Http::fakeSequence()->push($this->profile())->push(['statusCode' => 1])
            ->push($this->profile())->push($this->profile())->push($this->profile());
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('chưa xác minh');
        (new AttendanceDeviceDeleteService)->delete('00123', 'Example');
    }

    public function test_zero_id_is_refused_without_network_access(): void
    {
        $this->expectException(\RuntimeException::class);
        (new AttendanceDeviceDeleteService)->delete('0', 'Example');
    }
}
