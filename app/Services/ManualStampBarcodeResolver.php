<?php

namespace App\Services;

use App\Models\Product;
use App\Models\SendStamp;
use DomainException;

class ManualStampBarcodeResolver
{
    /** Resolve inside the scan transaction; never change the printed snapshot. */
    public function resolve(string $barcodeId, string $date, int $shift, int $bin): array
    {
        $stampDate = \DateTimeImmutable::createFromFormat('!dmY', $date);
        if (! $stampDate || $stampDate->format('dmY') !== $date) {
            throw new DomainException('Ngày trên barcode không hợp lệ.');
        }

        $stamps = SendStamp::where('manual_product_key', 'like', strtolower(substr($barcodeId, 1)).'%')
            ->whereNull('product_id')
            ->where('type', 'box')
            ->where('status', 'approve')
            ->where('date', $stampDate->format('Y-m-d'))
            ->where('shift', $shift)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->filter(function (SendStamp $stamp) use ($barcodeId, $bin) {
                if (($stamp->manual_product['barcode_id'] ?? null) !== $barcodeId) {
                    return false;
                }
                if (str_contains($stamp->binStart, ',')) {
                    $bins = array_slice(explode(',', $stamp->binStart), 0, (int) $stamp->binCount);

                    return in_array($bin, array_map('intval', $bins), true);
                }

                return $bin >= (int) $stamp->binStart && $bin < (int) $stamp->binStart + (int) $stamp->binCount;
            });

        if ($stamps->isEmpty()) {
            throw new DomainException('Không tìm thấy tem ngoài danh mục đã được duyệt cho barcode này.');
        }

        $identities = $stamps->map(fn ($stamp) => [
            $this->normalize($stamp->manual_product['name'] ?? ''),
            $this->normalize($stamp->manual_product['code'] ?? ''),
        ])->uniqueStrict()->values();
        $quantities = $stamps->map(fn ($stamp) => (int) ($stamp->manual_product['quanEntityBin'] ?? 0))->unique();
        if ($identities->count() !== 1 || in_array('', $identities[0], true) || $quantities->count() !== 1 || $quantities->first() < 1) {
            throw new DomainException('Barcode trùng thông tin tem khác nhau. Vui lòng kiểm tra lại lịch sử in tem.');
        }

        $linkedIds = $stamps->pluck('linked_product_id')->filter()->unique();
        if ($linkedIds->count() > 1) {
            throw new DomainException('Barcode đã liên kết với nhiều sản phẩm. Vui lòng kiểm tra lại lịch sử in tem.');
        }
        if ($linkedIds->isNotEmpty()) {
            $product = Product::lockForUpdate()->find($linkedIds->first());
            if (! $product) {
                throw new DomainException('Sản phẩm đã liên kết với tem không còn trong danh mục. Vui lòng báo quản trị viên.');
            }
        } else {
            [$name, $code] = $identities[0];
            $products = Product::whereRaw('LOWER(TRIM(code)) = ?', [$code])->lockForUpdate()->get()
                ->filter(fn ($product) => $this->normalize($product->code) === $code && $this->normalize($product->name) === $name);
            if ($products->isEmpty()) {
                throw new DomainException('Chưa có sản phẩm trùng tên và mã trong danh mục. Vui lòng báo quản trị viên thêm sản phẩm trước khi quét kho.');
            }
            if ($products->count() !== 1) {
                throw new DomainException('Có nhiều sản phẩm trùng tên và mã. Vui lòng báo quản trị viên kiểm tra trước khi quét kho.');
            }
            $product = $products->first();
        }

        foreach ($stamps as $stamp) {
            if (! $stamp->linked_product_id) {
                $stamp->linked_product_id = $product->id;
                $stamp->save();
            }
        }

        return ['product_id' => (int) $product->id, 'quantity_per_bin' => $quantities->first()];
    }

    private function normalize(?string $value): string
    {
        return mb_strtolower(trim($value ?? ''));
    }
}
