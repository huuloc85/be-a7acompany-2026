<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SidebarItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RBACController extends BaseController
{
    /**
     * GET /api/rbac
     * Lấy danh sách roles, permissions, và role-permission mapping.
     * Query params: role_ids[] (optional) - filter theo role IDs
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $roles = Role::select('id', 'role_name')->get();
            $permissions = Permission::select('id', 'key', 'name', 'type', 'module', 'display_area', 'icon', 'url', 'sort_order')
                ->orderBy('module')
                ->orderBy('sort_order')
                ->orderBy('type')
                ->get();

            $selectedRoleIds = (array) $request->input('role_ids', []);

            // Nếu không truyền role_ids, mặc định load TẤT CẢ roles
            if (empty($selectedRoleIds)) {
                $selectedRoleIds = $roles->pluck('id')->toArray();
            }

            // Load selected roles với permissions
            $selectedRoles = Role::with('permissions:id,key,name,type,module,display_area,icon,url,sort_order')
                ->whereIn('id', $selectedRoleIds)
                ->get(['id', 'role_name']);

            // Map: role_id => [permission_ids]
            $rolePermissions = $selectedRoles->mapWithKeys(function ($role) {
                return [$role->id => $role->permissions->pluck('id')->toArray()];
            });

            // Sidebar permissions & items
            $permissionsSidebar = Permission::whereIn('display_area', ['sidebar', 'both'])
                ->orderBy('module')
                ->orderBy('sort_order')
                ->get(['id', 'name', 'key', 'type', 'module', 'display_area', 'icon', 'url', 'sort_order']);

            $sidebarItemsByPermission = SidebarItem::whereIn('permission_id', $permissionsSidebar->pluck('id'))
                ->orderBy('id')
                ->get(['id', 'title', 'key', 'permission_id', 'icon', 'url'])
                ->groupBy('permission_id');

            return response()->json([
                'roles' => $roles,
                'permissions' => $permissions,
                'selected_roles' => $selectedRoles,
                'selected_role_ids' => array_map('intval', $selectedRoleIds),
                'role_permissions' => $rolePermissions,
                'permissions_sidebar' => $permissionsSidebar,
                'sidebar_items_by_permission' => $sidebarItemsByPermission,
            ]);
        } catch (\Throwable $e) {
            Log::error('RBAC index error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json(['message' => 'Có lỗi xảy ra khi tải dữ liệu RBAC.'], 500);
        }
    }

    /**
     * POST /api/rbac/save
     * Lưu phân quyền cho một hoặc nhiều roles.
     * Body: { role_ids: [1, 2], permissions: [3, 5, 7] }
     */
    public function save(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'role_ids' => 'required|array|min:1',
            'role_ids.*' => 'exists:roles,id',
            'permissions' => 'array',
            'permissions.*' => 'exists:permissions,id',
        ]);

        try {
            DB::beginTransaction();

            $permissions = $validated['permissions'] ?? [];
            $updatedRoles = Role::whereIn('id', $validated['role_ids'])->get();

            $updatedRoles->each(function ($role) use ($permissions) {
                $role->permissions()->sync($permissions);
            });

            DB::commit();

            // Reload roles with permissions
            $refreshedRoles = Role::with('permissions:id,key,name,type,module,display_area,icon,url,sort_order')
                ->whereIn('id', $validated['role_ids'])
                ->get(['id', 'role_name']);

            $rolePermissions = $refreshedRoles->mapWithKeys(function ($role) {
                return [$role->id => $role->permissions->pluck('id')->toArray()];
            });

            return response()->json([
                'message' => 'Phân quyền đã được cập nhật cho '.$updatedRoles->count().' vai trò: '.$updatedRoles->pluck('role_name')->implode(', '),
                'updated_roles' => $refreshedRoles,
                'role_permissions' => $rolePermissions,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('RBAC save error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json(['message' => 'Có lỗi xảy ra khi lưu phân quyền.'], 500);
        }
    }

    /**
     * POST /api/rbac/create-admin-user
     * Tạo Admin hoặc Co Admin user mới.
     * Body: { name: "Tên admin", role: "admin" | "co-admin" }
     */
    public function createAdminUser(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'role' => 'sometimes|string|in:admin,co-admin',
        ]);

        try {
            // Xác định role: mặc định là Admin
            $roleType = $validated['role'] ?? 'admin';
            $roleName = $roleType === 'co-admin' ? 'Co Admin' : 'Admin';

            $targetRole = Role::where('role_name', $roleName)->first();
            if (! $targetRole) {
                return response()->json(['message' => "Role {$roleName} không tồn tại."], 404);
            }

            // Tự động tìm số tiếp theo chưa bị trùng
            $maxPhoneNumber = Employee::withTrashed()->where('phone', 'like', 'ctyvinhvinhphat%')
                ->selectRaw("MAX(CAST(SUBSTRING(phone, LENGTH('ctyvinhvinhphat')+1) AS UNSIGNED)) as max_number")
                ->value('max_number') ?? 0;

            $maxAdminNumber = Employee::withTrashed()->where('id', 'like', 'Admin%')
                ->selectRaw('MAX(CAST(SUBSTRING(id, 6) AS UNSIGNED)) as max_number')
                ->value('max_number') ?? 0;

            $nextNumber = max($maxPhoneNumber, $maxAdminNumber) + 1;

            $adminId = 'Admin'.$nextNumber;
            $phone = 'ctyvinhvinhphat'.$nextNumber;

            // Đề phòng edge case: loop tìm số chưa dùng
            while (Employee::withTrashed()->where('id', $adminId)->orWhere('phone', $phone)->exists()) {
                $nextNumber++;
                $adminId = 'Admin'.$nextNumber;
                $phone = 'ctyvinhvinhphat'.$nextNumber;
            }

            $adminUser = new Employee([
                'id' => $adminId,
                'name' => $validated['name'],
                'phone' => $phone,
                'password' => bcrypt('123456'),
                'role_id' => $targetRole->id,
                'calendar_category_id' => 3,
            ]);
            // This endpoint supplies a string ID, not a database-generated ID.
            $adminUser->incrementing = false;
            $adminUser->save();

            return response()->json([
                'message' => 'Tạo thành công!',
                'admin_user' => [
                    'id' => (string) $adminUser->id,
                    'name' => $adminUser->name,
                    'phone' => $adminUser->phone,
                    'role_id' => $adminUser->role_id,
                    'role_name' => $roleName,
                ],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            \Illuminate\Support\Facades\Log::error(basename(__FILE__).' - '.__FUNCTION__.' - Error: '.$e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            Log::error('RBAC create admin user error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json(['message' => 'Có lỗi xảy ra khi tạo Admin user.'], 500);
        }
    }

    /**
     * GET /api/rbac/roles/{roleId}/permissions
     * Lấy danh sách permissions của một role cụ thể.
     */
    public function getRolePermissions(string $roleId): JsonResponse
    {
        try {
            $role = Role::with('permissions:id,key,name,type,module,display_area,icon,url,sort_order')
                ->findOrFail($roleId);

            return response()->json([
                'role' => [
                    'id' => $role->id,
                    'role_name' => $role->role_name,
                ],
                'permissions' => $role->permissions,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            \Illuminate\Support\Facades\Log::error(basename(__FILE__).' - '.__FUNCTION__.' - Error: '.$e->getMessage());

            return response()->json(['message' => 'Role không tồn tại.'], 404);
        } catch (\Throwable $e) {
            Log::error('RBAC get role permissions error', [
                'message' => $e->getMessage(),
                'role_id' => $roleId,
            ]);

            return response()->json(['message' => 'Có lỗi xảy ra.'], 500);
        }
    }

    /**
     * GET /api/rbac/admin-users
     * Lấy danh sách tất cả tài khoản Admin và Co Admin.
     * Query: ?search=keyword&role=admin|co-admin
     */
    public function getAdminUsers(Request $request): JsonResponse
    {
        try {
            // Lấy cả Admin và Co Admin role IDs
            $adminRoles = Role::whereIn('role_name', ['Admin', 'Co Admin'])->get();
            if ($adminRoles->isEmpty()) {
                return response()->json(['message' => 'Role Admin/Co Admin không tồn tại.'], 404);
            }

            $roleIds = $adminRoles->pluck('id')->toArray();
            $roleMap = $adminRoles->pluck('role_name', 'id')->toArray();

            $query = Employee::whereIn('role_id', $roleIds)
                ->select('id', 'name', 'phone', 'role_id', 'created_at', 'updated_at')
                ->selectRaw('(
                    (SELECT COUNT(*) FROM user_granted_permissions WHERE user_id = employees.id) +
                    (SELECT COUNT(*) FROM user_denied_permissions WHERE user_id = employees.id)
                ) as direct_permissions_count');

            // Filter theo role type
            if ($roleFilter = $request->input('role')) {
                $filterRoleName = $roleFilter === 'co-admin' ? 'Co Admin' : 'Admin';
                $filterRole = $adminRoles->firstWhere('role_name', $filterRoleName);
                if ($filterRole) {
                    $query->where('role_id', $filterRole->id);
                }
            }

            // Tìm kiếm theo tên hoặc phone
            if ($search = $request->input('search')) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('id', 'like', "%{$search}%");
                });
            }

            $adminUsers = $query->orderBy('created_at', 'desc')->get()
                ->map(function ($user) use ($roleMap) {
                    $user->role_name = $roleMap[$user->role_id] ?? 'Unknown';

                    return $user;
                });

            return response()->json([
                'admin_users' => $adminUsers,
                'total' => $adminUsers->count(),
            ]);
        } catch (\Throwable $e) {
            Log::error('RBAC get admin users error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json(['message' => 'Có lỗi xảy ra khi lấy danh sách admin.'], 500);
        }
    }

    /**
     * DELETE /api/rbac/admin-users/{id}
     * Xoá tài khoản Admin.
     */
    public function deleteAdminUser(string $id): JsonResponse
    {
        try {
            $adminUser = Employee::find($id);

            if (! $adminUser) {
                return response()->json(['message' => 'Tài khoản không tồn tại.'], 404);
            }

            // Không cho xoá Super Admin
            $superAdminRole = Role::where('role_name', 'Super Admin')->first();
            if ($superAdminRole && $adminUser->role_id === $superAdminRole->id) {
                return response()->json(['message' => 'Không thể xoá tài khoản Super Admin.'], 403);
            }

            // Không cho xoá chính mình
            if (auth()->id() === $adminUser->id) {
                return response()->json(['message' => 'Không thể xoá tài khoản đang đăng nhập.'], 403);
            }

            $deletedName = $adminUser->name;
            $deletedPhone = $adminUser->phone;

            // Revoke tất cả tokens trước khi xoá
            $adminUser->tokens()->delete();
            $adminUser->delete(); // Soft delete

            return response()->json([
                'message' => "Đã xoá tài khoản admin: {$deletedName} ({$deletedPhone})",
            ]);
        } catch (\Throwable $e) {
            Log::error('RBAC delete admin user error', [
                'message' => $e->getMessage(),
                'admin_id' => $id,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json(['message' => 'Có lỗi xảy ra khi xoá tài khoản admin.'], 500);
        }
    }

    /**
     * GET /api/rbac/admin-users/{id}/permissions
     * Lấy danh sách quyền của một admin user (role + granted + denied).
     */
    public function getUserPermissions(string $id): JsonResponse
    {
        try {
            $user = Employee::with(['role.permissions', 'grantedPermissions', 'deniedPermissions'])
                ->findOrFail($id);

            $rolePermissionIds = $user->role?->permissions->pluck('id')->toArray() ?? [];
            $grantedPermissionIds = $user->grantedPermissions->pluck('id')->toArray();
            $deniedPermissionIds = $user->deniedPermissions->pluck('id')->toArray();

            $allPermissions = Permission::with('sidebarItems')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();

            return response()->json([
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'phone' => $user->phone,
                    'role_id' => $user->role_id,
                    'role_name' => $user->role?->role_name ?? 'N/A',
                ],
                'role_permissions' => $rolePermissionIds,
                'granted_permissions' => $grantedPermissionIds,
                'denied_permissions' => $deniedPermissionIds,
                'all_permissions' => $allPermissions,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            \Illuminate\Support\Facades\Log::error(basename(__FILE__).' - '.__FUNCTION__.' - Error: '.$e->getMessage());

            return response()->json(['message' => 'User không tồn tại.'], 404);
        } catch (\Throwable $e) {
            Log::error('RBAC get user permissions error', [
                'message' => $e->getMessage(),
                'user_id' => $id,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json(['message' => 'Có lỗi xảy ra khi lấy quyền user.'], 500);
        }
    }

    /**
     * POST /api/rbac/admin-users/{id}/permissions
     * Lưu danh sách quyền granted + denied cho một admin user (sync).
     */
    public function saveUserPermissions(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'granted_ids' => 'sometimes|array',
            'granted_ids.*' => 'integer|exists:permissions,id',
            'denied_ids' => 'sometimes|array',
            'denied_ids.*' => 'integer|exists:permissions,id',
        ]);

        try {
            $user = Employee::findOrFail($id);

            $grantedIds = $request->input('granted_ids', []);
            $deniedIds = $request->input('denied_ids', []);

            // Sync cả 2 bảng
            $user->grantedPermissions()->sync($grantedIds);
            $user->deniedPermissions()->sync($deniedIds);

            $count = count($grantedIds) + count($deniedIds);

            return response()->json([
                'message' => 'Đã lưu phân quyền!',
                'direct_permissions_count' => $count,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            \Illuminate\Support\Facades\Log::error(basename(__FILE__).' - '.__FUNCTION__.' - Error: '.$e->getMessage());

            return response()->json(['message' => 'User không tồn tại.'], 404);
        } catch (\Throwable $e) {
            Log::error('RBAC save user permissions error', [
                'message' => $e->getMessage(),
                'user_id' => $id,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json(['message' => 'Có lỗi xảy ra khi lưu quyền user.'], 500);
        }
    }
}
