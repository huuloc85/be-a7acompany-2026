<?php

namespace App\Http\Controllers\Api\Admin;

use App\Events\AuthSessionRevoked;
use App\Helpers\HandleError;
use App\Helpers\LogActivity;
use App\Helpers\UploadHelper;
use App\Models\Celender;
use App\Models\CelenderDetailEatroom;
use App\Models\CelenderDetailWCCleanMen;
use App\Models\CelenderDetailWCCleanWomen;
use App\Models\Employee;
use App\Models\LoginHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

class AuthController extends BaseController
{
    /* API */

    public function authLogin(Request $request)
    {
        $credentials = $request->only('phone', 'password');

        if (! Auth::attempt($credentials)) {
            return response()->json(['message' => 'Số điện thoại hoặc mật khẩu không đúng!'], 401);
        }

        $user = Auth::user();

        if ($user->deleted_at !== null) {
            return response()->json(['message' => 'Bạn đã nghỉ việc!'], 403);
        }

        $employee = Employee::find($user->id);
        $today = now()->format('m-d');
        $birthdayEmployees = Employee::whereRaw("DATE_FORMAT(birthday, '%m-%d') = ?", [$today])
            ->whereNull('deleted_at')
            ->pluck('name');
        $isBirthday = $birthdayEmployees->isNotEmpty();

        $loginHistoryExists = LoginHistory::where('employee_id', $employee->id)
            ->whereDate('date', now()->toDateString())
            ->exists();

        $upcomingDuties = [];
        $calendar = Celender::latest('id')->first();
        if ($calendar) {
            for ($i = 0; $i < 3; $i++) {
                $checkDate = now()->addDays($i);
                $currentDay = 'day'.$checkDate->day;

                $duties = [
                    'Trực phòng ăn' => CelenderDetailEatroom::class,
                    'Trực nhà vệ sinh nữ' => CelenderDetailWCCleanWomen::class,
                    'Trực nhà vệ sinh nam' => CelenderDetailWCCleanMen::class,
                ];

                foreach ($duties as $dutyType => $model) {
                    if (
                        $model::where('celender_id', $calendar->id)
                            ->where('employee_id', $employee->id)
                            ->where($currentDay, 'x')
                            ->exists()
                    ) {
                        $upcomingDuties[] = [
                            'date' => $checkDate->format('Y-m-d'),
                            'type' => $dutyType,
                        ];
                        break;
                    }
                }
            }
        }

        // Xóa các token cũ của user này (optional)
        // $user->tokens()->delete();

        // Nhận giá trị expiresInMins từ request, mặc định 60 phút
        $expiresInMins = $request->input('expiresInMins', 60);
        $expiresAt = now()->addMinutes($expiresInMins);
        $sessionId = (string) Str::uuid();
        $loggedInElsewhere = $user->tokens()
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->exists();

        // Tạo token với thời gian hết hạn
        $tokenResult = $user->createToken('auth_token', ['*'], $expiresAt);

        // Lưu expires_at vào token
        $tokenResult->accessToken->expires_at = $expiresAt;
        $tokenResult->accessToken->session_id = $sessionId;
        $tokenResult->accessToken->save();
        $permissionSelect = ['permissions.id', 'permissions.key', 'permissions.name', 'permissions.type', 'permissions.display_area', 'permissions.icon', 'permissions.url', 'permissions.sort_order'];
        $sidebarEager = [
            'sidebarItems' => function ($q) {
                $q->select(['id', 'permission_id', 'key', 'title', 'icon', 'url']);
            },
        ];

        // 1. Lấy quyền từ Role
        $rolePermissions = $user->role
            ->permissions()
            ->select($permissionSelect)
            ->orderBy('permissions.sort_order')
            ->with($sidebarEager)
            ->get();

        // 2. Lấy danh sách quyền bị deny
        $deniedIds = $user->deniedPermissions()->pluck('permissions.id')->toArray();

        // 3. Lọc bỏ quyền bị deny khỏi role permissions
        $permissions = $rolePermissions->filter(fn ($p) => ! in_array($p->id, $deniedIds));

        // 4. Lấy quyền được grant thêm
        $grantedPermissions = $user->grantedPermissions()
            ->select($permissionSelect)
            ->with($sidebarEager)
            ->get();

        // 5. Merge granted + unique + sort
        $permissions = $permissions->merge($grantedPermissions)
            ->unique('id')
            ->sortBy('sort_order')
            ->values();

        $roleName = in_array($employee->role_id, [15, 21, 22]) ? 'Admin' : 'Nhân Viên';
        LogActivity::logViewActivity($user, "{$roleName} Đăng Nhập", "{$roleName} đã đăng nhập vào web");

        return response()->json([
            'id' => $user->id,
            'role_id' => $user->role_id,
            'role_name' => $user->role->role_name,
            'gender' => $user->gender,
            'name' => $user->name,
            'image' => $user->photo,
            'is_birthday' => $isBirthday,
            'birthday_employees' => $birthdayEmployees,
            'cleaning_duties' => $upcomingDuties,
            'token' => $tokenResult->plainTextToken,
            'session_id' => $sessionId,
            'auth_channel_name' => AuthSessionRevoked::channelNameFor($user),
            'logged_in_elsewhere' => $loggedInElsewhere,
            'login_conflict_message' => $loggedInElsewhere
                ? 'Tài khoản này đang được đăng nhập ở một nơi khác.'
                : null,
            'permissions' => $permissions,
        ])->cookie('auth_token', $tokenResult->plainTextToken, $expiresInMins, null, null, false, true);
    }

    public function authLogout(Request $request)
    {
        $token = $request->bearerToken();
        if ($token) {
            PersonalAccessToken::findToken($token)?->delete();
        }

        return response()->json(['message' => 'Đăng xuất thành công'])
            ->cookie('auth_token', null, -1, null, null, true, true);
    }

    public function authConfirmLogin(Request $request)
    {
        $user = Auth::user();
        $token = $user?->currentAccessToken();

        if (! $user || ! ($token instanceof PersonalAccessToken)) {
            return response()->json(['message' => 'Token không hợp lệ'], 401);
        }

        $sessionId = $token->session_id;

        if (! $sessionId) {
            $sessionId = (string) Str::uuid();
            $token->forceFill(['session_id' => $sessionId])->save();
        }

        $user->tokens()
            ->where('id', '!=', $token->id)
            ->delete();

        event(new AuthSessionRevoked($user, $sessionId));

        return response()->json([
            'message' => 'Đăng nhập tiếp tục thành công',
            'session_id' => $sessionId,
        ]);
    }

    public function authCheck()
    {
        $user = Auth::user();

        if ($user) {
            $token = $user->currentAccessToken();
            $sessionId = $token instanceof PersonalAccessToken ? $token->session_id : null;

            if ($token instanceof PersonalAccessToken && ! $sessionId) {
                $sessionId = (string) Str::uuid();
                $token->forceFill(['session_id' => $sessionId])->save();
            }

            return response()->json([
                'role_id' => $user->role_id,
                'role_name' => $user->role->role_name,
                'name' => $user->name,
                'image' => $user->photo,
                'session_id' => $sessionId,
                'auth_channel_name' => AuthSessionRevoked::channelNameFor($user),
            ], 200);
        } else {
            return response()->json([
                'error' => [
                    'code' => 401,
                    'message' => 'Unauthorized',
                ],
            ], 401);
        }
    }

    public function authProfile()
    {
        $id = auth()->user()->id;
        try {
            $employee = Employee::query()
                ->select(
                    'id',
                    'name',
                    'phone',
                    'email',
                    'address',
                    'home_town',
                    'gender',
                    'birthday',
                    'CCCD',
                    'photo',
                    'card_photo',
                    'marital_status',
                    'date_joining',
                    'company',
                    'role_id',
                    'calendar_category_id',
                    'created_at',
                    'updated_at',
                )
                ->with([
                    'role:id,role_name',
                    'calendarCategory:id,name',
                ])
                ->where('id', $id)
                ->firstOrFail();

            return response()->json($employee, 200);
        } catch (\Throwable $e) {
            return HandleError::handle($e);
        }
    }

    public function authChangeProfile(Request $request)
    {
        DB::beginTransaction();
        try {
            $id = auth()->user()->id;
            $employee = Employee::query()
                ->findOrFail($id);

            $validated = $request->validate([
                'name' => 'sometimes|string|max:255',
                'phone' => 'sometimes|string|regex:/^0[0-9]{9}$/|unique:employees,phone,'.$id,
                'email' => 'nullable|email|unique:employees,email,'.$id,
                'CCCD' => 'sometimes|string|regex:/^[0-9]+$/|unique:employees,cccd,'.$id,
                'address' => 'sometimes|string',
                'home_town' => 'sometimes|string',
                'birthday' => 'sometimes|date|before:today|after:1900-01-01',
                'gender' => 'sometimes|in:male,female,other',
                'marital_status' => 'sometimes|in:single,married,divorced,widowed',
            ]);

            $employee->update($validated);

            DB::commit();

            return response()->json([
                'message' => 'Employee updated successfully!',
                'data' => $employee,
            ], 200);
        } catch (\Throwable $e) {
            DB::rollBack();

            return HandleError::handle($e);
        }
    }

    public function changePassword(Request $request)
    {
        try {
            $validate = $request->validate([
                'password' => 'required|string',
                'newPassword' => 'required|string|min:6',
                'confirmPassword' => 'required|string|min:6',
            ]);
            $user = Auth::user();
            $employee = Employee::find($user->id);

            if (! $employee) {
                return response()->json(['message' => 'User not found'], 404);
            }

            $current = $validate['password'];
            $new = $validate['newPassword'];
            $confirm = $validate['confirmPassword'];

            if (! Hash::check($current, $employee->password)) {
                return response()->json(['message' => 'Mật khẩu hiện tại không đúng'], 422);
            }

            if ($new !== $confirm) {
                return response()->json(['message' => 'Xác nhận mật khẩu không khớp'], 422);
            }

            // Cập nhật mật khẩu (Model Employee đã có cast 'hashed', KHÔNG dùng bcrypt() thủ công)
            $employee->password = $new;
            $employee->save();

            LogActivity::logViewActivity($user, 'Đổi mật khẩu', 'Đã đổi mật khẩu thành công');

            return response()->json(['message' => 'Thay đổi mật khẩu thành công'], 200);
        } catch (Throwable $e) {
            \Illuminate\Support\Facades\Log::error(basename(__FILE__).' - '.__FUNCTION__.' - Error: '.$e->getMessage());

            return response()->json(['message' => 'Thay đổi mật khẩu thất bại', 'error' => $e->getMessage()], 500);
        }
    }

    public function resetPassword($id)
    {
        try {
            $employee = Employee::findOrFail($id);
            // Reset mật khẩu về mã nhân viên (Model Employee đã có cast 'hashed', KHÔNG dùng bcrypt() thủ công)
            $employee->password = $id;
            $employee->save();

            $admin = Auth::user();
            LogActivity::logViewActivity($admin, 'Reset mật khẩu', "Admin đã reset mật khẩu cho nhân viên: {$employee->name} (ID: {$id})");

            return response()->json([
                'message' => 'Khôi phục mật khẩu thành công',
            ], 200);
        } catch (Throwable $e) {
            \Illuminate\Support\Facades\Log::error(basename(__FILE__).' - '.__FUNCTION__.' - Error: '.$e->getMessage());

            return response()->json(['message' => 'Khôi phục mật khẩu thất bại', 'error' => $e->getMessage()], 500);
        }
    }

    // make change avatar
    public function changeAvatar(Request $request)
    {
        DB::beginTransaction();
        try {
            $id = auth()->user()->id;
            $employee = Employee::query()
                ->select('id', 'photo')
                ->findOrFail($id);

            $validated = $request->validate([
                'photo' => 'required|image|mimes:jpeg,png,jpg',
            ]);

            $oldPhoto = $employee->photo;

            // Process new avatar
            if ($request->hasFile('photo')) {
                $file = $request->file('photo');
                $validated['photo'] = UploadHelper::upload($file, 'photo');
            }

            $employee->update($validated);

            DB::commit();

            // Delete old photo after successful update
            if ($oldPhoto) {
                Storage::delete('public/employee/'.$oldPhoto);
            }

            return response()->json([
                'message' => 'Avatar updated successfully!',
                'data' => [
                    'id' => $employee->id,
                    'photo' => $employee->photo,
                ],
            ], 200);
        } catch (\Throwable $e) {
            DB::rollBack();

            return HandleError::handle($e);
        }
    }

    public function getBirthdayEmployees()
    {
        $today = Carbon::today();
        $employees = Employee::whereMonth('birthday', $today->month)
            ->whereDay('birthday', $today->day)
            ->get();

        return response()->json($employees);
    }
}
