<?php

namespace App\Http\Controllers\Api\Employee;

use App\Events\StampNotificationEvent;
use App\Helpers\HandleError;
use App\Helpers\LogActivity;
use App\Http\Controllers\Controller;
use App\Models\SendStamp;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\QueryBuilder\QueryBuilder;

class EmpStampController extends Controller
{
    public function requestStamp(Request $request)
    {
        DB::beginTransaction();
        try {
            $user = Auth::user();
            $validate = $request->validate([
                'stamps' => 'required|array',
                'stamps.*.type' => 'required|in:box,bag',
                'stamps.*.productId' => 'required|exists:products,id',
                'stamps.*.date' => 'required|date_format:Y-m-d',
                'stamps.*.shift' => 'required|in:1,2',
                'stamps.*.binCount' => 'required|integer|min:1',
                'stamps.*.binStart' => 'required|string',
                'stamps.*.purpose' => 'nullable|in:new,additional,reprint',
            ]);

            // Check for duplicate requests in the same batch
            $this->validateDuplicateInBatch($validate['stamps']);

            // Validate all stamps and collect errors
            $allErrors = [];
            foreach ($validate['stamps'] as $index => $stamp) {
                try {
                    // Validate binStart format
                    $this->validateBinStart($stamp['binStart'], $index);
                } catch (\Illuminate\Validation\ValidationException $e) {
                    \Illuminate\Support\Facades\Log::error(basename(__FILE__).' - '.__FUNCTION__.' - Error: '.$e->getMessage());
                    $allErrors = array_merge($allErrors, $e->errors());
                }

                try {
                    // Validate purpose logic
                    $this->validatePurposeLogic($stamp, $index);
                } catch (\Illuminate\Validation\ValidationException $e) {
                    \Illuminate\Support\Facades\Log::error(basename(__FILE__).' - '.__FUNCTION__.' - Error: '.$e->getMessage());
                    $allErrors = array_merge($allErrors, $e->errors());
                }
            }

            // If there are any validation errors, throw them all at once
            if (! empty($allErrors)) {
                throw \Illuminate\Validation\ValidationException::withMessages($allErrors);
            }

            // Create stamp records
            $stamps = [];
            foreach ($validate['stamps'] as $index => $stamp) {
                $stamps[] = SendStamp::create([
                    'employee_id' => $user->id,
                    'type' => $stamp['type'],
                    'product_id' => $stamp['productId'],
                    'date' => Carbon::parse($stamp['date'])->toDateString(),
                    'shift' => $stamp['shift'],
                    'binCount' => $stamp['binCount'] ?? 1,
                    'binStart' => $stamp['binStart'],
                    'status' => 'pending',
                    'purpose' => $stamp['purpose'] ?? null,
                ]);
            }

            $roles = [8, 21, 13];

            foreach ($stamps as $stamp) {
                foreach ($roles as $role) {
                    event(new StampNotificationEvent($stamp, $role));
                }
            }
            DB::commit();

            LogActivity::logViewActivity(auth()->user(), 'Yêu Cầu In Tem', 'Nhân viên gửi yêu cầu in tem');

            return response()->json(
                [
                    'employee_id' => $user->id,
                    'stamps' => $validate['stamps'],
                ],
                201
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            \Illuminate\Support\Facades\Log::error(basename(__FILE__).' - '.__FUNCTION__.' - Error: '.$e->getMessage());
            DB::rollBack();

            return response()->json([
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $th) {
            DB::rollBack();

            return HandleError::handle($th);
        }
    }

    public function getStampHistory(Request $request)
    {
        try {
            $user = Auth::user();
            $roleName = preg_replace('/\s+/', ' ', strtolower(trim($user->role->role_name)));
            $roleName = preg_replace('/^(co|super)[-_]admin$/', '$1 admin', $roleName);
            $canViewAll = in_array($roleName, ['admin', 'co admin', 'super admin'], true);
            $stampHistory = QueryBuilder::for(SendStamp::class)
                ->when(! $canViewAll, fn ($query) => $query->where('employee_id', $user->id))
                ->allowedFilters([
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
                ->allowedIncludes(['product', 'employee', 'manager']);

            $limit = $request->get('limit', 10);
            if ($limit == 0) {
                $limit = $stampHistory->count();
            }

            $stampHistory = $stampHistory->paginate($limit);

            LogActivity::logViewActivity(auth()->user(), 'Xem Lịch Sử Yêu Cầu In Tem', 'Nhân viên xem lịch sử yêu cầu in tem');

            return response()->json($stampHistory, 200);
        } catch (\Throwable $e) {
            return HandleError::handle($e);
        }
    }

    /**
     * Validate binStart string - có thể là một số hoặc nhiều số cách nhau bởi dấu phẩy
     */
    private function validateBinStart(string $binStart, int $index = 0): void
    {
        $numbers = array_map('trim', explode(',', $binStart));

        foreach ($numbers as $number) {
            if (! is_numeric($number) || (int) $number < 1) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    "stamps.{$index}.binStart" => [[
                        'code' => 'STAMP_BINSTART_INVALID_FORMAT',
                        'params' => [
                            'invalidValue' => $number,
                            'binStart' => $binStart,
                        ],
                    ]],
                ]);
            }
        }
    }

    /**
     * Check for duplicate stamp requests in the same batch
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    private function validateDuplicateInBatch(array $stamps): void
    {
        $seen = [];
        $duplicates = [];

        foreach ($stamps as $index => $stamp) {
            // Create unique key: product_id + date + shift + type + purpose + binStart
            $key = sprintf(
                '%s_%s_%s_%s_%s_%s',
                $stamp['productId'],
                $stamp['date'],
                $stamp['shift'],
                $stamp['type'],
                $stamp['purpose'] ?? 'new',
                $stamp['binStart']
            );

            if (isset($seen[$key])) {
                // Found duplicate - add both original and current index
                if (! isset($duplicates[$key])) {
                    $duplicates[$key] = [$seen[$key]];
                }
                $duplicates[$key][] = $index;
            } else {
                $seen[$key] = $index;
            }
        }

        // If duplicates found, throw validation error
        if (! empty($duplicates)) {
            $errors = [];
            foreach ($duplicates as $key => $indices) {
                $firstIndex = $indices[0];
                $duplicateIndices = array_slice($indices, 1);

                foreach ($duplicateIndices as $dupIndex) {
                    $errors["stamps.{$dupIndex}.binStart"] = [[
                        'code' => 'STAMP_BATCH_DUPLICATE',
                        'params' => [
                            'duplicateIndex' => $dupIndex,
                            'originalIndex' => $firstIndex,
                            'originalRequestNumber' => $firstIndex + 1,
                        ],
                    ]];
                }
            }

            throw \Illuminate\Validation\ValidationException::withMessages($errors);
        }
    }

    /**
     * Validate purpose logic according to business rules
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    private function validatePurposeLogic(array $stamp, int $index = 0): void
    {
        $purpose = $stamp['purpose'] ?? 'new'; // Default to 'new' if not provided
        $binStart = $stamp['binStart'];
        $binCount = $stamp['binCount'];
        $productId = $stamp['productId'];
        $date = $stamp['date'];
        $shift = $stamp['shift'];
        $type = $stamp['type'];

        // Get first stamp number from binStart
        $firstStamp = $this->getFirstStampNumber($binStart);

        switch ($purpose) {
            case 'new':
                // "In mới" - Must start from 1
                if ($firstStamp !== 1) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        "stamps.{$index}.binStart" => [[
                            'code' => 'STAMP_NEW_MUST_START_FROM_ONE',
                            'params' => [
                                'firstStamp' => $firstStamp,
                            ],
                        ]],
                    ]);
                }

                // Check if stamps already exist (approved)
                $maxStamp = $this->getMaxStampNumber($productId, $date, $shift, $type);

                if ($maxStamp !== null) {
                    // Calculate user's intended range: generate all stamps from binStart + binCount
                    $allStampNumbers = $this->getAllStampNumbers($binStart);

                    // Calculate the full range including binCount
                    $userStamps = [];
                    if (count($allStampNumbers) >= $binCount) {
                        $userStamps = array_slice($allStampNumbers, 0, $binCount);
                    } else {
                        $userStamps = $allStampNumbers;
                        $lastNum = max($allStampNumbers);
                        while (count($userStamps) < $binCount) {
                            $lastNum++;
                            $userStamps[] = $lastNum;
                        }
                    }

                    // Check which stamps overlap with existing stamps using detailed check
                    $duplicateInfo = $this->checkStampDuplicates($userStamps, $productId, $date, $shift, $type);

                    if ($duplicateInfo['exists']) {
                        $duplicates = $duplicateInfo['duplicates'];
                        $userFirstStamp = min($userStamps);
                        $userLastStamp = max($userStamps);
                        $duplicateFirst = min($duplicates);
                        $duplicateLast = max($duplicates);

                        // Use dateDetails from duplicateInfo
                        $dateDetails = $duplicateInfo['dateDetails'];

                        throw \Illuminate\Validation\ValidationException::withMessages([
                            "stamps.{$index}.binStart" => [[
                                'code' => 'STAMP_NEW_ALREADY_PRINTED',
                                'params' => [
                                    'userRangeFirst' => $userFirstStamp,
                                    'userRangeLast' => $userLastStamp,
                                    'duplicateFirst' => $duplicateFirst,
                                    'duplicateLast' => $duplicateLast,
                                    'maxStamp' => $maxStamp,
                                    'nextStamp' => $maxStamp + 1,
                                    'dateDetails' => $dateDetails,
                                ],
                            ]],
                        ]);
                    }
                }
                break;

            case 'additional':
                // "In thêm" - Must continue from last approved stamp
                // Get max stamp from database
                $maxStamp = $this->getMaxStampNumber($productId, $date, $shift, $type);

                if ($maxStamp === null) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        "stamps.{$index}.binStart" => [[
                            'code' => 'STAMP_ADDITIONAL_NO_EXISTING_STAMPS',
                            'params' => [],
                        ]],
                    ]);
                }

                $expectedStart = $maxStamp + 1;

                // Calculate user's full intended range
                $allStampNumbers = $this->getAllStampNumbers($binStart);

                // Calculate the full range including binCount
                $userStamps = [];
                if (count($allStampNumbers) >= $binCount) {
                    // binStart already contains enough or more stamps
                    $userStamps = array_slice($allStampNumbers, 0, $binCount);
                } else {
                    // Need to extend: start with binStart numbers, then continue sequentially
                    $userStamps = $allStampNumbers;
                    $lastNum = max($allStampNumbers);
                    while (count($userStamps) < $binCount) {
                        $lastNum++;
                        $userStamps[] = $lastNum;
                    }
                }

                // Check if user is trying to print already approved stamps using detailed check
                $duplicateInfo = $this->checkStampDuplicates($userStamps, $productId, $date, $shift, $type);

                if ($duplicateInfo['exists']) {
                    $duplicates = $duplicateInfo['duplicates'];
                    $userFirstStamp = min($userStamps);
                    $userLastStamp = max($userStamps);
                    $duplicateFirst = min($duplicates);
                    $duplicateLast = max($duplicates);

                    // Use dateDetails from duplicateInfo
                    $dateDetails = $duplicateInfo['dateDetails'];

                    throw \Illuminate\Validation\ValidationException::withMessages([
                        "stamps.{$index}.binStart" => [[
                            'code' => 'STAMP_ADDITIONAL_ALREADY_PRINTED',
                            'params' => [
                                'userRangeFirst' => $userFirstStamp,
                                'userRangeLast' => $userLastStamp,
                                'duplicateFirst' => $duplicateFirst,
                                'duplicateLast' => $duplicateLast,
                                'dateDetails' => $dateDetails,
                            ],
                        ]],
                    ]);
                }

                if ($firstStamp !== $expectedStart) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        "stamps.{$index}.binStart" => [[
                            'code' => 'STAMP_ADDITIONAL_WRONG_START',
                            'params' => [
                                'expectedStart' => $expectedStart,
                                'actualStart' => $firstStamp,
                                'maxStamp' => $maxStamp,
                            ],
                        ]],
                    ]);
                }

                // Additional validation: Check if any stamp number in the middle is <= maxStamp
                $allStampNumbers = $this->getAllStampNumbers($binStart);
                $alreadyPrintedStamps = array_filter($allStampNumbers, function ($num) use ($maxStamp) {
                    return $num <= $maxStamp;
                });

                if (! empty($alreadyPrintedStamps)) {
                    $minUserStamp = min($alreadyPrintedStamps);
                    $maxUserStamp = max($alreadyPrintedStamps);

                    throw \Illuminate\Validation\ValidationException::withMessages([
                        "stamps.{$index}.binStart" => [[
                            'code' => 'STAMP_ADDITIONAL_MIDDLE_ALREADY_PRINTED',
                            'params' => [
                                'duplicateFirst' => $minUserStamp,
                                'duplicateLast' => $maxUserStamp,
                                'maxStamp' => $maxStamp,
                            ],
                        ]],
                    ]);
                }
                break;

            case 'reprint':
                // "In lại" - No validation needed
                break;

            default:
                // Default to 'new' logic if purpose is null or invalid
                if ($firstStamp !== 1) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        "stamps.{$index}.binStart" => [[
                            'code' => 'STAMP_NEW_MUST_START_FROM_ONE',
                            'params' => [
                                'firstStamp' => $firstStamp,
                            ],
                        ]],
                    ]);
                }
                break;
        }
    }

    /**
     * Get first stamp number from binStart string
     */
    private function getFirstStampNumber(string $binStart): int
    {
        if (strpos($binStart, ',') !== false) {
            // Multiple numbers separated by comma - get the smallest
            $numbers = array_map('intval', array_map('trim', explode(',', $binStart)));

            return min($numbers);
        } else {
            // Single number
            return (int) $binStart;
        }
    }

    /**
     * Get all stamp numbers from binStart string
     */
    private function getAllStampNumbers(string $binStart): array
    {
        if (strpos($binStart, ',') !== false) {
            // Multiple numbers separated by comma
            return array_map('intval', array_map('trim', explode(',', $binStart)));
        } else {
            // Single number
            return [(int) $binStart];
        }
    }

    /**
     * Get max stamp number from database for a specific product/date/shift/type
     */
    private function getMaxStampNumber(string $productId, string $date, int $shift, string $type): ?int
    {
        // Query APPROVED stamps for the same product/date/shift/type
        $stamps = SendStamp::where('product_id', $productId)
            ->where('date', $date)
            ->where('shift', $shift)
            ->where('type', $type)
            ->where('status', 'approve') // Only count approved stamps
            ->select('binStart', 'binCount')
            ->get();

        if ($stamps->isEmpty()) {
            return null;
        }

        $maxStamp = 0;
        foreach ($stamps as $stamp) {
            $binStart = $stamp->binStart;
            $binCount = $stamp->binCount;

            // Parse binStart to get all stamp numbers
            if (strpos($binStart, ',') !== false) {
                // Multiple numbers - get the largest
                $numbers = array_map('intval', array_map('trim', explode(',', $binStart)));
                $max = max($numbers);
            } else {
                // Single number - calculate last stamp: binStart + binCount - 1
                $startNum = (int) $binStart;
                $max = $startNum + $binCount - 1;
            }

            if ($max > $maxStamp) {
                $maxStamp = $max;
            }
        }

        return $maxStamp > 0 ? $maxStamp : null;
    }

    /**
     * Check which stamps from userStamps already exist and return detailed info
     * Only check within same product/date/shift/type
     *
     * @param  array  $userStamps  - Array of stamp numbers user wants to print
     * @return array ['exists' => bool, 'duplicates' => [numbers], 'dates' => [date => [stamps]]]
     */
    private function checkStampDuplicates(array $userStamps, string $productId, string $date, int $shift, string $type): array
    {
        // Query APPROVED stamps for the same product/date/shift/type (same printing session)
        $stamps = SendStamp::where('product_id', $productId)
            ->where('date', $date)
            ->where('shift', $shift)
            ->where('type', $type)
            ->where('status', 'approve')
            ->select('binStart', 'binCount', 'date', 'shift')
            ->get();

        if ($stamps->isEmpty()) {
            return ['exists' => false, 'duplicates' => [], 'dates' => []];
        }

        $existingStamps = []; // [stampNumber => ['date' => ..., 'shift' => ...]]

        foreach ($stamps as $stamp) {
            $binStart = $stamp->binStart;
            $binCount = $stamp->binCount;
            $stampDate = $stamp->date;
            $stampShift = (int) $stamp->shift; // Cast to int immediately

            // Parse binStart to get all stamp numbers
            $stampNumbers = [];
            if (strpos($binStart, ',') !== false) {
                $startNumbers = array_map('intval', array_map('trim', explode(',', $binStart)));
                foreach ($startNumbers as $num) {
                    $stampNumbers[] = $num;
                }
            } else {
                $startNum = (int) $binStart;
                for ($i = 0; $i < $binCount; $i++) {
                    $stampNumbers[] = $startNum + $i;
                }
            }

            // Store each stamp number with its date and shift (as int)
            foreach ($stampNumbers as $num) {
                if (! isset($existingStamps[$num])) {
                    $existingStamps[$num] = [
                        'date' => $stampDate,
                        'shift' => $stampShift, // Already int from above
                    ];
                }
            }
        }

        // Check which user stamps already exist
        $duplicates = [];
        $dateGroups = []; // Group by date first, then by shift within each date

        foreach ($userStamps as $num) {
            if (isset($existingStamps[$num])) {
                $duplicates[] = $num;
                $existDate = $existingStamps[$num]['date'];
                $existShift = $existingStamps[$num]['shift'];

                // Group by date first
                if (! isset($dateGroups[$existDate])) {
                    $dateGroups[$existDate] = [];
                }

                // Then group by shift within that date
                if (! isset($dateGroups[$existDate][$existShift])) {
                    $dateGroups[$existDate][$existShift] = [];
                }

                $dateGroups[$existDate][$existShift][] = $num;
            }
        }

        // Convert dateGroups to consolidated array format
        $dateDetails = [];
        foreach ($dateGroups as $date => $shifts) {
            // Collect all stamps for this date across all shifts
            $allStampsForDate = [];
            $shiftDetails = [];

            foreach ($shifts as $shift => $stamps) {
                $allStampsForDate = array_merge($allStampsForDate, $stamps);
                $shiftDetails[] = [
                    'shift' => (int) $shift,  // Cast to int
                    'stamps' => $stamps,
                    'stampFirst' => min($stamps),
                    'stampLast' => max($stamps),
                ];
            }

            // Sort shifts by shift number
            usort($shiftDetails, function ($a, $b) {
                return $a['shift'] - $b['shift'];
            });

            $dateEntry = [
                'date' => $date,
                'stamps' => $allStampsForDate,
                'stampFirst' => min($allStampsForDate),
                'stampLast' => max($allStampsForDate),
                'shifts' => $shiftDetails, // Detailed breakdown by shift
            ];

            // Add top-level 'shift' field for backward compatibility when only 1 shift
            if (count($shiftDetails) === 1) {
                $dateEntry['shift'] = $shiftDetails[0]['shift'];
            }

            $dateDetails[] = $dateEntry;
        }

        return [
            'exists' => ! empty($duplicates),
            'duplicates' => $duplicates,
            'dateDetails' => $dateDetails,
        ];
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

            Log::info('Employee check duplicate request', ['request' => $validated, 'employee_id' => Auth::id()]);

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
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            \Illuminate\Support\Facades\Log::error(basename(__FILE__).' - '.__FUNCTION__.' - Error: '.$e->getMessage());

            return response()->json([
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Error checking duplicate stamps (Employee)', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'employee_id' => Auth::id(),
            ]);

            return response()->json([
                'error' => 'INTERNAL_SERVER_ERROR',
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
}
