<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\Permission;
use App\Models\SidebarItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class PermissionController extends BaseController
{
    /**
     * GET /api/permissions
     * Lấy danh sách tất cả permissions, phân nhóm theo display_area.
     */
    public function index(): JsonResponse
    {
        try {
            $sidebarItemQuery = fn ($q) => $q->select('id', 'permission_id', 'key', 'title', 'icon', 'url');

            // Permissions hiện trên Home: 'home' và 'both'
            $homePermissions = Permission::whereIn('display_area', ['home', 'both'])
                ->orderBy('module')
                ->orderBy('sort_order')
                ->orderBy('type')
                ->get(['id', 'key', 'name', 'type', 'module', 'display_area', 'icon', 'url', 'sort_order', 'created_at', 'updated_at']);

            // Permissions hiện trên Sidebar: 'sidebar' và 'both' + load sidebarItems
            $sidebarPermissions = Permission::whereIn('display_area', ['sidebar', 'both'])
                ->with(['sidebarItems' => $sidebarItemQuery])
                ->orderBy('module')
                ->orderBy('sort_order')
                ->orderBy('type')
                ->get(['id', 'key', 'name', 'type', 'module', 'display_area', 'icon', 'url', 'sort_order', 'created_at', 'updated_at']);

            // Tất cả permissions (không phân nhóm)
            $allPermissions = Permission::with(['sidebarItems' => $sidebarItemQuery])
                ->orderBy('module')
                ->orderBy('sort_order')
                ->orderBy('type')
                ->get(['id', 'key', 'name', 'type', 'module', 'display_area', 'icon', 'url', 'sort_order', 'created_at', 'updated_at']);

            return response()->json([
                'home_permissions' => $homePermissions,
                'sidebar_permissions' => $sidebarPermissions,
                'all_permissions' => $allPermissions,
            ]);
        } catch (\Throwable $e) {
            Log::error('Permission index error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json(['message' => 'Có lỗi xảy ra khi tải danh sách permissions.'], 500);
        }
    }

    /**
     * GET /api/permissions/{id}
     * Lấy chi tiết một permission.
     */
    public function show(int $id): JsonResponse
    {
        try {
            $permission = Permission::with(['sidebarItems' => fn ($q) => $q->select('id', 'permission_id', 'key', 'title', 'icon', 'url')])
                ->findOrFail($id);

            return response()->json([
                'permission' => $permission,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            \Illuminate\Support\Facades\Log::error(basename(__FILE__).' - '.__FUNCTION__.' - Error: '.$e->getMessage());

            return response()->json(['message' => 'Permission không tồn tại.'], 404);
        } catch (\Throwable $e) {
            Log::error('Permission show error', [
                'message' => $e->getMessage(),
                'permission_id' => $id,
            ]);

            return response()->json(['message' => 'Có lỗi xảy ra.'], 500);
        }
    }

    /**
     * POST /api/permissions
     * Tạo permission mới.
     * Body: { key, name, type, display_area }
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'key' => 'required|string|max:255|unique:permissions,key',
            'name' => 'required|string|max:255',
            'type' => 'required|in:admin,employee,both',
            'module' => 'nullable|string|max:100',
            'display_area' => 'required|in:home,sidebar,both',
            'icon' => 'nullable|string|max:255',
            'url' => 'nullable|string|max:255',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        try {
            $permission = DB::transaction(function () use ($validated) {
                return Permission::create($validated);
            });

            return response()->json([
                'message' => 'Tạo quyền thành công!',
                'permission' => $permission,
            ], 201);
        } catch (\Throwable $e) {
            Log::error('Permission store error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'input' => $validated,
            ]);

            // Check for unique constraint violation
            if (str_contains($e->getMessage(), 'permissions_key_unique') || str_contains($e->getMessage(), 'permissions_key_display_area_unique')) {
                return response()->json(['message' => 'Mã quyền đã tồn tại.'], 422);
            }

            return response()->json(['message' => 'Có lỗi xảy ra khi tạo quyền.'], 500);
        }
    }

    /**
     * PUT /api/permissions/{id}
     * Cập nhật permission.
     * Body: { key?, name?, type?, display_area? }
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'key' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('permissions', 'key')->ignore($id)],
            'name' => 'sometimes|string|max:255',
            'type' => 'sometimes|in:admin,employee,both',
            'module' => 'nullable|string|max:100',
            'display_area' => 'sometimes|in:home,sidebar,both',
            'icon' => 'nullable|string|max:255',
            'url' => 'nullable|string|max:255',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        try {
            $permission = Permission::findOrFail($id);

            DB::transaction(function () use ($permission, $validated) {
                $permission->update($validated);
            });

            $permission->refresh();
            $permission->load(['sidebarItems' => fn ($q) => $q->select('id', 'permission_id', 'key', 'title', 'icon', 'url')]);

            return response()->json([
                'message' => 'Cập nhật quyền thành công!',
                'permission' => $permission,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            \Illuminate\Support\Facades\Log::error(basename(__FILE__).' - '.__FUNCTION__.' - Error: '.$e->getMessage());

            return response()->json(['message' => 'Permission không tồn tại.'], 404);
        } catch (\Throwable $e) {
            Log::error('Permission update error', [
                'permission_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'input' => $validated,
            ]);

            if (str_contains($e->getMessage(), 'permissions_key_unique') || str_contains($e->getMessage(), 'permissions_key_display_area_unique')) {
                return response()->json(['message' => 'Mã quyền đã tồn tại.'], 422);
            }

            return response()->json(['message' => 'Có lỗi xảy ra khi cập nhật quyền.'], 500);
        }
    }

    /**
     * DELETE /api/permissions/{id}
     * Xóa permission. Sidebar items sẽ tự xóa theo (onDelete cascade trong DB).
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $permission = Permission::findOrFail($id);
            $deletedSidebarCount = $permission->sidebarItems()->count();

            DB::transaction(function () use ($permission) {
                // sidebar_items sẽ tự cascade delete nhờ FK constraint trong migration
                // KHÔNG set permission_id = null vì column là NOT NULL
                $permission->delete();
            });

            return response()->json([
                'message' => 'Xóa quyền thành công!'.($deletedSidebarCount > 0 ? " (đã xóa {$deletedSidebarCount} sidebar items liên quan)" : ''),
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            \Illuminate\Support\Facades\Log::error(basename(__FILE__).' - '.__FUNCTION__.' - Error: '.$e->getMessage());

            return response()->json(['message' => 'Permission không tồn tại.'], 404);
        } catch (\Throwable $e) {
            Log::error('Permission destroy error', [
                'permission_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Có lỗi xảy ra khi xóa quyền.'], 500);
        }
    }

    // ========================
    // SIDEBAR ITEMS
    // ========================

    /**
     * GET /api/permissions/sidebar-items/all
     * Lấy tất cả sidebar items (grouped by permission_id).
     */
    public function getSidebarItems(): JsonResponse
    {
        try {
            $sidebarItems = SidebarItem::with('permission:id,key,name,type,module,display_area,icon,url,sort_order')
                ->orderBy('id')
                ->get(['id', 'permission_id', 'key', 'title', 'icon', 'url']);

            return response()->json([
                'sidebar_items' => $sidebarItems,
                'grouped_by_permission' => $sidebarItems->groupBy('permission_id'),
            ]);
        } catch (\Throwable $e) {
            Log::error('Get sidebar items error', [
                'message' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Có lỗi xảy ra.'], 500);
        }
    }

    /**
     * POST /api/permissions/sidebar-items
     * Tạo sidebar item mới.
     * Body: { permission_id, key, title, icon?, url? }
     */
    public function storeSidebarItem(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'permission_id' => 'required|exists:permissions,id',
            'key' => 'required|string|max:255|unique:sidebar_items,key',
            'title' => 'required|string|max:255',
            'icon' => 'nullable|string|max:255',
            'url' => 'nullable|string|max:255',
        ]);

        try {
            $sidebarItem = SidebarItem::create($validated);
            $sidebarItem->load('permission:id,key,name');

            return response()->json([
                'message' => 'Tạo SidebarItem thành công!',
                'sidebar_item' => $sidebarItem,
            ], 201);
        } catch (\Throwable $e) {
            Log::error('Store sidebar item error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'input' => $validated,
            ]);

            return response()->json(['message' => 'Có lỗi xảy ra khi tạo SidebarItem.'], 500);
        }
    }

    /**
     * PATCH /api/permissions/sidebar-items/{sidebarItemId}
     * Cập nhật sidebar item.
     * Body: { permission_id?, key?, title?, icon?, url? }
     */
    public function updateSidebarItem(Request $request, int $sidebarItemId): JsonResponse
    {
        $validated = $request->validate([
            'permission_id' => 'sometimes|exists:permissions,id',
            'key' => 'sometimes|string|max:255',
            'title' => 'sometimes|string|max:255',
            'icon' => 'nullable|string|max:255',
            'url' => 'nullable|string|max:255',
        ]);

        try {
            $sidebarItem = SidebarItem::findOrFail($sidebarItemId);

            // Check key uniqueness (exclude current item)
            if (isset($validated['key']) && $validated['key'] !== $sidebarItem->key) {
                if (SidebarItem::where('key', $validated['key'])->where('id', '!=', $sidebarItem->id)->exists()) {
                    return response()->json(['message' => 'Key của SidebarItem đã tồn tại!'], 422);
                }
            }

            $sidebarItem->update($validated);
            $sidebarItem->refresh();
            $sidebarItem->load('permission:id,key,name');

            return response()->json([
                'message' => 'Cập nhật SidebarItem thành công!',
                'sidebar_item' => $sidebarItem,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            \Illuminate\Support\Facades\Log::error(basename(__FILE__).' - '.__FUNCTION__.' - Error: '.$e->getMessage());

            return response()->json(['message' => 'SidebarItem không tồn tại.'], 404);
        } catch (\Throwable $e) {
            Log::error('Update sidebar item error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'sidebar_item_id' => $sidebarItemId,
                'input' => $validated,
            ]);

            return response()->json(['message' => 'Có lỗi xảy ra khi cập nhật SidebarItem.'], 500);
        }
    }

    /**
     * DELETE /api/permissions/sidebar-items/{sidebarItemId}
     * Xóa sidebar item.
     */
    public function destroySidebarItem(int $sidebarItemId): JsonResponse
    {
        try {
            $sidebarItem = SidebarItem::findOrFail($sidebarItemId);
            $sidebarItem->delete();

            return response()->json([
                'message' => 'Xóa SidebarItem thành công!',
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            \Illuminate\Support\Facades\Log::error(basename(__FILE__).' - '.__FUNCTION__.' - Error: '.$e->getMessage());

            return response()->json(['message' => 'SidebarItem không tồn tại.'], 404);
        } catch (\Throwable $e) {
            Log::error('Destroy sidebar item error', [
                'message' => $e->getMessage(),
                'sidebar_item_id' => $sidebarItemId,
            ]);

            return response()->json(['message' => 'Có lỗi xảy ra khi xóa SidebarItem.'], 500);
        }
    }
}
