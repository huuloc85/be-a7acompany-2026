<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\LoginHistory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class EmployeePreviewService
{
    public const PREFIX = 'employee-preview_';

    public function start(Employee $actor, PersonalAccessToken $parent, string $employeeId, string $password): array
    {
        abort_unless($this->isSuperAdmin($actor) && (string) $parent->tokenable_id === (string) $actor->id && $parent->tokenable_type === $actor->getMorphClass() && $this->validToken($parent), 403);
        if (! Hash::check($password, $actor->password)) {
            throw ValidationException::withMessages(['password' => 'Mật khẩu quản trị không đúng.']);
        }
        $employee = Employee::findOrFail($employeeId);
        abort_unless($this->isEmployee($employee) && (string) $employee->id !== (string) $actor->id, 403, 'Chỉ được xem tài khoản nhân viên đang làm việc.');
        $expiresAt = now()->addMinutes(15);
        if ($parent->expires_at && $parent->expires_at->lt($expiresAt)) {
            $expiresAt = $parent->expires_at;
        }
        $secret = self::PREFIX.Str::random(64);
        $session = [
            'actor_id' => (string) $actor->id,
            'parent_token_id' => $parent->id,
            'employee_id' => (string) $employee->id,
            'expires_at' => $expiresAt->toIso8601String(),
        ];
        // Never issue a normal employee token or revoke an employee's sessions.
        $payload = $this->payload($employee, $session);
        $this->audit($actor, $employee, 'Bắt đầu xem', $session['expires_at']);
        Cache::put($this->key($secret), $session, $expiresAt);

        return ['token' => $secret, ...$payload];
    }

    public function resolve(string $secret): ?array
    {
        $session = Cache::get($this->key($secret));
        if (! is_array($session) || now()->gte($session['expires_at'])) {
            return null;
        }
        $parent = PersonalAccessToken::find($session['parent_token_id']);
        $actor = $parent?->tokenable;
        $employee = Employee::find($session['employee_id']);
        if (! $parent || ! $this->validToken($parent) || ! ($actor instanceof Employee) || (string) $actor->id !== $session['actor_id'] || ! $this->isSuperAdmin($actor) || ! $employee || ! $this->isEmployee($employee)) {
            return null;
        }

        return compact('session', 'parent', 'actor', 'employee');
    }

    public function stop(string $secret, array $context): void
    {
        Cache::forget($this->key($secret));
        $this->audit($context['actor'], $context['employee'], 'Kết thúc xem', $context['session']['expires_at']);
    }

    public function payload(Employee $employee, array $session): array
    {
        $permissions = $employee->getEffectivePermissions()->load('sidebarItems')->sortBy('sort_order')->values();

        return [
            'id' => (string) $employee->id,
            'name' => $employee->name,
            'gender' => $employee->gender,
            'image' => $employee->photo,
            'role_id' => $employee->role_id,
            'role_name' => $employee->role->role_name,
            'permissions' => $permissions,
            'session_id' => null,
            'auth_channel_name' => null,
            'preview_expires_at' => $session['expires_at'],
        ];
    }

    public function isSuperAdmin(Employee $employee): bool
    {
        return $employee->deleted_at === null && strtolower(trim($employee->role?->role_name ?? '')) === 'super admin';
    }

    private function isEmployee(Employee $employee): bool
    {
        $role = strtolower(trim($employee->role?->role_name ?? ''));

        return $employee->deleted_at === null && $role !== '' && ! in_array($role, ['super admin', 'admin', 'co admin', 'super-admin', 'co-admin', 'super_admin', 'co_admin'], true);
    }

    private function validToken(PersonalAccessToken $token): bool
    {
        $expiration = config('sanctum.expiration');

        return (! $token->expires_at || $token->expires_at->isFuture())
            && (! $expiration || $token->created_at->gt(now()->subMinutes($expiration)));
    }

    private function key(string $secret): string
    {
        return 'employee-preview:'.hash('sha256', $secret);
    }

    private function audit(Employee $actor, Employee $employee, string $action, string $expiresAt): void
    {
        LoginHistory::create([
            'employee_id' => $actor->id,
            'employee_code' => $actor->id,
            'employee_name' => $actor->name,
            'activity_type' => 'Xem với tư cách nhân viên',
            'description' => "$action: {$employee->name} ({$employee->id}). Chỉ xem; hết hạn $expiresAt.",
            'date' => now()->toDateString(),
            'month_history' => now()->format('Y-m'),
            'login_count' => 1,
        ]);
    }
}
