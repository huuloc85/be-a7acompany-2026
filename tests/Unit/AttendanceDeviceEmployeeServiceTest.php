<?php

namespace Tests\Unit;

use App\Services\AttendanceDeviceEmployeeService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class AttendanceDeviceEmployeeServiceTest extends TestCase
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

    private function profile(): array
    {
        return ['employeeNo' => 'NEW001', 'name' => 'Test employee', 'doorRight' => '1',
            'RightPlan' => [['doorNo' => 1, 'planTemplateNo' => '1']],
            'userVerifyMode' => 'faceOrFpOrCardOrPw'];
    }

    public function test_create_includes_rights_and_verifies_result(): void
    {
        Http::fakeSequence()->push(['UserInfoSearch' => ['totalMatches' => 0]])
            ->push(['statusCode' => 1])->push(['UserInfoSearch' => ['UserInfo' => [$this->profile()]]]);
        (new AttendanceDeviceEmployeeService)->sync('NEW001', 'Test employee');
        Http::assertSentCount(3);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/Record?') && $r->method() === 'POST'
            && $r['UserInfo']['doorRight'] === '1' && $r['UserInfo']['RightPlan'][0]['planTemplateNo'] === '1'
            && $r['UserInfo']['userVerifyMode'] === 'faceOrFpOrCardOrPw' && ! isset($r['UserInfo']['fingerData']));
    }

    public function test_retry_updates_same_person_without_recreating_or_sending_biometrics(): void
    {
        $profile = $this->profile();
        $profile['Valid'] = ['enable' => true, 'beginTime' => '2026-01-01T00:00:00', 'endTime' => '2036-01-01T23:59:59'];
        Http::fakeSequence()->push(['UserInfoSearch' => ['UserInfo' => [$profile]]])
            ->push(['statusCode' => 1])->push(['UserInfoSearch' => ['UserInfo' => [$profile]]]);
        (new AttendanceDeviceEmployeeService)->sync('NEW001', 'Test employee');
        Http::assertSent(fn ($r) => $r->method() === 'PUT' && str_contains($r->url(), '/Modify?')
            && $r['UserInfo']['Valid'] === $profile['Valid'] && ! isset($r['UserInfo']['fingerData']));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/Record?') || str_contains($r->url(), '/Delete?'));
    }

    public function test_existing_other_person_is_not_overwritten(): void
    {
        $profile = $this->profile();
        $profile['name'] = 'Someone else';
        Http::fakeSequence()->push(['UserInfoSearch' => ['UserInfo' => [$profile]]]);
        try {
            (new AttendanceDeviceEmployeeService)->sync('NEW001', 'Test employee');
            self::fail('Expected conflict');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('hồ sơ khác', $e->getMessage());
            Http::assertSentCount(1);
        }
    }

    public function test_http_200_with_device_failure_is_not_success(): void
    {
        Http::fakeSequence()->push(['UserInfoSearch' => ['totalMatches' => 0]])->push(['statusCode' => 4]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('từ chối');
        (new AttendanceDeviceEmployeeService)->sync('NEW001', 'Test employee');
    }

    public function test_readback_without_rights_is_not_success(): void
    {
        $profile = $this->profile();
        $profile['doorRight'] = '';
        Http::fakeSequence()->push(['UserInfoSearch' => ['totalMatches' => 0]])
            ->push(['statusCode' => 1])->push(['UserInfoSearch' => ['UserInfo' => [$profile]]]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Chưa xác minh');
        (new AttendanceDeviceEmployeeService)->sync('NEW001', 'Test employee');
    }
}
