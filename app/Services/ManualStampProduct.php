<?php

namespace App\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;

class ManualStampProduct
{
    public static function authorize(): void
    {
        $user = auth()->user();
        $roleName = preg_replace('/\s+/', ' ', strtolower(trim($user?->role?->role_name ?? '')));
        $roleName = preg_replace('/^(co|super)[-_]admin$/', '$1 admin', $roleName);
        $superAdmin = $roleName === 'super admin';
        if (! $user || (! $superAdmin && ! $user->hasPermission('print_manual_label'))) {
            throw new AuthorizationException('Bạn không có quyền in tem ngoài danh mục.');
        }
    }

    public static function fromRequest(Request $request): array
    {
        self::authorize();

        $validated = $request->validate([
            'type' => 'required|in:box,bag',
            'manual_product' => 'required|array',
            'manual_product.name' => 'required|string|max:100',
            'manual_product.code' => 'required|string|max:50',
            'manual_product.material' => 'nullable|string|max:50',
            'manual_product.color' => 'nullable|string|max:30',
            'manual_product.quanEntityBin' => 'required_if:type,box|nullable|integer|min:1|max:999999',
            'manual_product.quantity_per_package' => 'required_if:type,bag|nullable|integer|min:1|max:999999',
        ]);
        $product = $validated['manual_product'];
        foreach (['name', 'code', 'material', 'color'] as $field) {
            $product[$field] = trim($product[$field] ?? '');
        }
        foreach (['name', 'code'] as $field) {
            if ($product[$field] === '') {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'manual_product.'.$field => 'Vui lòng nhập thông tin sản phẩm.',
                ]);
            }
        }
        $key = hash('sha256', mb_strtolower(preg_replace('/\s+/u', ' ', $product['code'])));

        return [
            'name' => $product['name'],
            'code' => $product['code'],
            'material' => $product['material'],
            'color' => $product['color'],
            'quanEntityBin' => isset($product['quanEntityBin']) ? (int) $product['quanEntityBin'] : null,
            'quantity_per_package' => isset($product['quantity_per_package']) ? (int) $product['quantity_per_package'] : null,
            'key' => $key,
            // Không khớp định dạng product ID dạng số của mã vạch kho.
            'barcode_id' => 'M'.strtoupper(substr($key, 0, 20)),
        ];
    }
}
