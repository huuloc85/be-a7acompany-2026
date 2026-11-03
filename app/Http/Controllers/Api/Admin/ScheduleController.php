<?php

namespace App\Http\Controllers\Api\Admin;

use App\Events\ScheduleCreated;
use App\Helpers\HandleError;
use App\Helpers\LogActivity;
use App\Imports\Celender\CelenderManagerImport;
use App\Models\CategoryCelender;
use App\Models\Celender;
use App\Models\CelenderDetailEatroom;
use App\Models\CelenderDetailHNHC;
use App\Models\CelenderDetailWC;
use App\Models\CelenderDetailWCCleanMen;
use App\Models\CelenderDetailWCCleanWomen;
use App\Models\Employee;
use App\Models\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\QueryBuilder\QueryBuilder;

class ScheduleController extends BaseController
{
    public function index(Request $request)
    {
        try {
            $schedules = QueryBuilder::for(Schedule::class)
                ->allowedFilters([
                    'title',
                    'date',
                    'created_at',
                    'updated_at',
                ])
                ->allowedFields([
                    'id',
                    'title',
                    'date',
                    'created_at',
                    'updated_at',
                ])
                ->defaultSort('-date')
                ->allowedSorts([
                    'title',
                    'date',
                ]);

            $limit = $request->limit;
            if (! is_null($limit) && $limit == 0) {
                $limit = $schedules->count();
            }
            $schedules = $schedules->paginate($limit ?? 10);

            return response()->json($schedules);

        } catch (\Throwable $th) {
            return HandleError::handle($th);
        }
    }

    public function show(Request $request, $id)
    {
        try {
            $schedule = Schedule::findOrFail($id);

            return response()->json($schedule);
        } catch (\Throwable $th) {
            return HandleError::handle($th);
        }
    }

    public function create(Request $request)
    {
        DB::beginTransaction();
        try {
            $request->validate([
                'title' => 'required|string|max:255',
                'date' => 'required|date',
                'fileImport' => 'nullable|file|mimes:xlsx',
            ]);

            $countCategoryCelender = CategoryCelender::count();

            if ($countCategoryCelender <= 0) {
                DB::rollBack();

                return response()->json([
                    'status' => false,
                    'message' => 'Bạn phải thêm danh mục lịch làm việc trước khi thêm lịch làm việc!',
                ], 422);
            }

            $employees = Employee::where('role_id', '!=', 15)
                ->where('role_id', '!=', 16)
                ->where('role_id', '!=', 17)
                ->get();

            if (count($employees) <= 0) {
                DB::rollBack();

                return response()->json([
                    'status' => false,
                    'message' => 'Bạn phải thêm nhân sự trước khi thêm lịch làm việc!',
                ], 422);
            }

            $celender = new Celender;
            $celender->title = $request->title;
            $celender->date = $request->date;
            $celender->save();

            $excelFile = null;
            if ($request->file('fileImport')) {
                $excelFile = $request->file('fileImport')->store('temp');
            }

            if ($excelFile != null && $celender != null) {
                Excel::import(new CelenderManagerImport($celender->id), $excelFile);
                Log::info('Import excel file for celender ID: '.$celender->id);
            }

            DB::commit();
            LogActivity::logRoleSpecificLoginActivity(auth()->user(), 'Admin Thêm Lịch Làm Việc', 'Admin đã thêm lịch làm việc');

            // Broadcast thông báo đến nhân viên qua Pusher
            broadcast(new ScheduleCreated($celender));

            return response()->json([
                'status' => true,
                'message' => 'Thêm lịch làm việc mới thành công!',
                'data' => $celender,
                'excelFile' => $excelFile,
            ], 201);
        } catch (\Throwable $th) {
            DB::rollBack();

            return HandleError::handle($th);
        }
    }

    public function delete($id)
    {
        DB::beginTransaction();
        try {
            $celender = Celender::find($id);

            if (! $celender) {
                DB::rollBack();

                return response()->json([
                    'status' => false,
                    'message' => 'Không tìm thấy lịch làm việc',
                ], 404);
            }

            CelenderDetailHNHC::where('celender_id', $id)->delete();
            CelenderDetailEatroom::where('celender_id', $id)->delete();
            CelenderDetailWC::where('celender_id', $id)->delete();
            CelenderDetailWCCleanWomen::where('celender_id', $id)->delete();
            CelenderDetailWCCleanMen::where('celender_id', $id)->delete();
            $celender->delete();

            LogActivity::logRoleSpecificLoginActivity(auth()->user(), 'Admin Xoá Lịch Làm Việc', 'Admin đã xoá lịch làm việc');

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Xoá lịch làm việc thành công!',
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Errors: '.$e->getMessage().' getLine: '.$e->getLine());

            return response()->json([
                'status' => false,
                'message' => 'Xoá lịch làm việc không thành công!',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function detail($id)
    {
        try {
            $schedule = Celender::select('id', 'title', 'date')->find($id);

            if (! $schedule) {
                return response()->json([
                    'status' => false,
                    'message' => 'Không tìm thấy lịch làm việc',
                ], 404);
            }

            $scheduleDetailsHNHC = CelenderDetailHNHC::where('celender_id', $id)
                ->whereHas('employee', function ($query) {
                    $query->where('deleted_at', '=', null);
                })
                ->with('employee')
                ->get();

            $scheduleDetailsEatRoom = CelenderDetailEatroom::where('celender_id', $id)
                ->whereHas('employee', function ($query) {
                    $query->where('deleted_at', '=', null);
                })
                ->with('employee')
                ->orderBy('celender_detail_eatroom.id')
                ->get();

            $scheduleDetailsWC = CelenderDetailWC::where('celender_id', $id)
                ->whereHas('employee', function ($query) {
                    $query->where('deleted_at', '=', null);
                })
                ->with('employee')
                ->get();

            $scheduleDetailsWCCleanWomen = CelenderDetailWCCleanWomen::where('celender_id', $id)
                ->whereHas('employee', function ($query) {
                    $query->where('deleted_at', '=', null);
                })
                ->with('employee')
                ->get();

            $scheduleDetailsWCCleanMen = CelenderDetailWCCleanMen::where('celender_id', $id)
                ->whereHas('employee', function ($query) {
                    $query->where('deleted_at', '=', null);
                })
                ->with('employee')
                ->get();

            $categories = CategoryCelender::all()->select('id', 'name');

            return response()->json(
                [
                    'schedule' => $schedule,
                    'scheduleDetailsHNHC' => $scheduleDetailsHNHC,
                    'scheduleDetailsEatRoom' => $scheduleDetailsEatRoom,
                    'scheduleDetailsWC' => $scheduleDetailsWC,
                    'scheduleDetailsWCCleanWomen' => $scheduleDetailsWCCleanWomen,
                    'scheduleDetailsWCCleanMen' => $scheduleDetailsWCCleanMen,
                    'categories' => $categories,
                ],
                200
            );
        } catch (\Exception $e) {
            Log::error('Errors: '.$e->getMessage().' getLine: '.$e->getLine());

            return response()->json([
                'status' => false,
                'message' => 'Lấy chi tiết lịch làm việc không thành công!',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
