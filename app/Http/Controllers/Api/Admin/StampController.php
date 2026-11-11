<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\HandleError;
use App\Models\SendStamp;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\QueryBuilder\QueryBuilder;

class StampController extends BaseController
{
    public function savePrint(Request $request)
    {
        try {
            DB::beginTransaction();
            Log::info('Request to save print received', ['request' => $request->all()]);

            $validation = $request->validate([
                'productId' => 'required|exists:products,id',
                'type' => 'required|string|in:box,bag,Tem Thùng,Tem Bịch',
                'date' => 'required|date_format:Y-m-d',
                'shift' => 'required|in:1,2',
                'binCount' => 'required|integer|min:1',
                'binStart' => 'required|regex:/^[0-9]+(,[0-9]+)*$/',
                'employee_id' => 'sometimes|exists:employees,id',
                'stamp_id' => 'sometimes|exists:send_stamps,id',
                'purpose' => 'nullable|in:new,additional,reprint',
            ]);

            if ($request['stamp_id']) {
                return $this->approveExistingStamp($validation['stamp_id']);
            }

            $createdStamps = $this->createStampsFromBinList($validation);

            Log::info('Print saved successfully', ['created_stamps_count' => count($createdStamps)]);
            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Print saved successfully',
                'data' => [
                    'stamps_created' => count($createdStamps),
                    'stamps' => $createdStamps->map(function ($stamp) {
                        return [
                            'id' => $stamp->id,
                            'product_id' => $stamp->product_id,
                            'type' => $stamp->type,
                            'date' => $stamp->date,
                            'shift' => $stamp->shift,
                            'binCount' => $stamp->binCount,
                            'binStart' => $stamp->binStart,
                        ];
                    }),
                ],
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();

            return HandleError::handle($e);
        }
    }

    public function getStampHistory(Request $request)
    {
        try {
            $stampHistory = QueryBuilder::for(SendStamp::class)
                ->allowedFilters([
                    'employee_id',
                    'manager_id',
                    'date',
                    'shift',
                    'status',
                    'binCount',
                    'binStart',
                    'type',
                    'purpose',
                    'product.name',
                    'employee.name',
                    'created_at',
                ])
                ->allowedSorts(['created_at'])
                ->defaultSort('-created_at')
                ->allowedIncludes(['product', 'employee', 'manager'])
                ->paginate($request->get('limit', 10));

            return response()->json($stampHistory, 200);
        } catch (\Throwable $e) {
            return HandleError::handle($e);
        }
    }

    public function deleteStampHistory($id)
    {
        DB::beginTransaction();
        try {
            $stamp = SendStamp::findOrFail($id);
            $stamp->delete();

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Stamp history deleted successfully',
            ], 200);
        } catch (\Throwable $e) {
            DB::rollBack();

            return HandleError::handle($e);
        }
    }

    public function rejectPrint($id)
    {
        DB::beginTransaction();
        try {
            $stamp = SendStamp::findOrFail($id);
            $stamp->update([
                'status' => 'rejected',
                'manager_id' => auth()->id(),
                'manager_time' => Carbon::now()->format('H:i:s'),
            ]);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Print request rejected successfully',
            ], 200);
        } catch (\Throwable $e) {
            DB::rollBack();

            return HandleError::handle($e);
        }
    }

    /**
     * Check for duplicate stamps before printing
     */
    public function checkDuplicate(Request $request)
    {
        try {
            // Validate request
            $validated = $request->validate([
                'product_id' => 'required|string',
                'date' => 'required|date_format:Y-m-d',
                'shift' => 'required|in:1,2',
                'binStart' => 'required|string|regex:/^[0-9]+(,[0-9]+)*$/',
                'binCount' => 'required|integer|min:1',
                'type' => 'required|string|in:box,bag',
            ]);

            Log::info('Check duplicate request', ['request' => $validated]);

            // Query existing approved stamps with purpose = 'new'
            $existingStamps = SendStamp::where('product_id', $validated['product_id'])
                ->where('date', $validated['date'])
                ->where('shift', $validated['shift'])
                ->where('type', $validated['type'])
                ->where('status', 'approve')
                ->where('purpose', 'new')
                ->select('id', 'binStart', 'binCount')
                ->get();

            Log::info('Found existing stamps', ['count' => $existingStamps->count()]);

            // Calculate request stamp range
            $requestStamps = $this->getStampRange($validated['binStart'], $validated['binCount']);

            // Check for duplicates
            $duplicates = [];
            foreach ($existingStamps as $existingStamp) {
                $existingStampRange = $this->getStampRange($existingStamp->binStart, $existingStamp->binCount);

                // Find overlapping stamps
                $overlappingStamps = array_intersect($requestStamps, $existingStampRange);

                if (! empty($overlappingStamps)) {
                    $duplicates[] = [
                        'id' => $existingStamp->id,
                        'binStart' => $existingStamp->binStart,
                        'binCount' => $existingStamp->binCount,
                        'overlappingStamps' => array_values($overlappingStamps),
                    ];
                }
            }

            $isDuplicate = ! empty($duplicates);

            return response()->json([
                'isDuplicate' => $isDuplicate,
                'duplicates' => $duplicates,
                'message' => $isDuplicate ? 'Phát hiện tem trùng lặp' : 'Không có tem trùng lặp',
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            \Illuminate\Support\Facades\Log::error(basename(__FILE__).' - '.__FUNCTION__.' - Error: '.$e->getMessage());

            return response()->json([
                'error' => 'Validation Error',
                'message' => 'Missing required fields',
                'details' => $e->errors(),
            ], 400);
        } catch (\Throwable $e) {
            Log::error('Error checking duplicate stamps', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Server Error',
                'message' => 'Error checking duplicate stamps',
            ], 500);
        }
    }

    /**
     * Get stamp range from binStart and binCount
     */
    private function getStampRange(string $binStart, int $binCount): array
    {
        if (strpos($binStart, ',') !== false) {
            // Parse comma-separated values
            $stamps = array_map('intval', array_map('trim', explode(',', $binStart)));

            // Return only the first $binCount stamps
            return array_slice($stamps, 0, $binCount);
        } else {
            // Generate continuous range
            $start = (int) $binStart;

            return range($start, $start + $binCount - 1);
        }
    }

    /**
     * Approve existing stamp request
     */
    private function approveExistingStamp(int $stampId)
    {
        $originalStamp = SendStamp::lockForUpdate()->findOrFail($stampId);
        if ($originalStamp->status === 'approve') {
            DB::commit();

            return response()->json(['status' => 'success', 'message' => 'Tem đã được duyệt.', 'data' => $originalStamp]);
        }
        if ($originalStamp->status !== 'pending') {
            throw \Illuminate\Validation\ValidationException::withMessages(['stamp_id' => 'Yêu cầu in tem đã được xử lý.']);
        }

        // Giữ nguyên một yêu cầu in lại thành một dòng lịch sử.
        if ($originalStamp->purpose !== 'reprint' && strpos($originalStamp->binStart, ',') !== false) {
            return $this->splitStampIntoBins($originalStamp);
        }

        // Nếu không có dấu phẩy thì approve bình thường
        $originalStamp->update([
            'status' => 'approve',
            'manager_id' => Auth()->user()->id,
            'manager_time' => Carbon::now()->format('H:i:s'),
        ]);

        Log::info('Stamp updated successfully', ['stamp_id' => $originalStamp->id]);
        DB::commit();

        return response()->json([
            'status' => 'success',
            'message' => 'Stamp updated successfully',
            'data' => $originalStamp,
        ], 200);
    }

    /**
     * Keep reprints as one operation; create separate bins for other purposes.
     */
    private function createStampsFromBinList(array $validation)
    {
        \App\Models\Product::lockForUpdate()->findOrFail($validation['productId']);
        $binList = array_unique($this->getStampRange($validation['binStart'], $validation['binCount']));
        $createdStamps = collect();

        if (($validation['purpose'] ?? null) === 'reprint') {
            $bins = array_values($binList);
            $binStart = $bins === range($bins[0], $bins[0] + count($bins) - 1)
                ? (string) $bins[0]
                : implode(',', $bins);

            return collect([SendStamp::create([
                'product_id' => $validation['productId'],
                'type' => $validation['type'],
                'date' => $validation['date'],
                'shift' => $validation['shift'] ?? 1,
                'binStart' => $binStart,
                'binCount' => count($bins),
                'status' => 'approve',
                'purpose' => 'reprint',
                'manager_id' => Auth()->user()->id,
                'employee_id' => $validation['employee_id'] ?? Auth()->user()->id,
                'manager_time' => Carbon::now()->format('H:i:s'),
            ])]);
        }

        foreach ($binList as $bin) {
            $bin = trim($bin);

            $key = [
                'product_id' => $validation['productId'],
                'type' => $validation['type'],
                'date' => $validation['date'],
                'shift' => $validation['shift'] ?? 1,
                'binStart' => $bin, // Mỗi bin tách thành record riêng
                'status' => 'approve',
                'purpose' => $validation['purpose'] ?? null,
            ];
            $values = [
                'manager_id' => Auth()->user()->id,
                'employee_id' => $validation['employee_id'] ?? Auth()->user()->id,
                'manager_time' => Carbon::now()->format('H:i:s'),
                'binCount' => 1, // Mỗi record chỉ có 1 bin
            ];
            $stamp = SendStamp::firstOrCreate($key, $values);

            $createdStamps->push($stamp);
        }

        return $createdStamps;
    }

    /**
     * Split a pending stamp with comma-separated binStart into multiple approved records
     */
    private function splitStampIntoBins(SendStamp $originalStamp)
    {
        $binList = explode(',', $originalStamp->binStart);
        $createdStamps = collect();

        foreach ($binList as $bin) {
            $bin = trim($bin);

            // Tạo record mới cho mỗi bin
            $newStamp = SendStamp::create([
                'employee_id' => $originalStamp->employee_id,
                'product_id' => $originalStamp->product_id,
                'manager_id' => Auth()->user()->id,
                'type' => $originalStamp->type,
                'date' => $originalStamp->date,
                'shift' => $originalStamp->shift,
                'binCount' => 1, // Mỗi record chỉ có 1 bin
                'binStart' => $bin, // Từng số riêng biệt
                'manager_time' => Carbon::now()->format('H:i:s'),
                'status' => 'approve',
                'purpose' => $originalStamp->purpose,
            ]);

            $createdStamps->push($newStamp);
        }

        // Xóa record pending gốc
        $originalStamp->delete();

        Log::info('Stamp split into multiple bins', [
            'original_stamp_id' => $originalStamp->id,
            'bins_created' => count($createdStamps),
        ]);

        DB::commit();

        return response()->json([
            'status' => 'success',
            'message' => 'Stamp approved and split into individual bins successfully',
            'data' => [
                'original_stamp_id' => $originalStamp->id,
                'bins_created' => count($createdStamps),
                'stamps' => $createdStamps->map(function ($stamp) {
                    return [
                        'id' => $stamp->id,
                        'binStart' => $stamp->binStart,
                        'binCount' => $stamp->binCount,
                        'type' => $stamp->type,
                        'date' => $stamp->date,
                        'shift' => $stamp->shift,
                    ];
                }),
            ],
        ], 200);
    }
}
