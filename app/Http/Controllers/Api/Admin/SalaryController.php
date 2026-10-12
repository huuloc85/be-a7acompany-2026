<?php

namespace App\Http\Controllers\Api\Admin;

use App\Events\SalaryCreated;
use App\Helpers\HandleError;
use App\Imports\A7A\SalaryOfficialA7AManagerImport;
use App\Imports\VVP\SalaryOfficialVVPManagerImport;
use App\Models\SalaryManager;
use App\Models\SalaryOfficialA7A;
use App\Models\SalaryOfficialA7ATimekeeping;
use App\Models\SalaryOfficialVVP;
use App\Models\SalaryOfficialVVPTimekeeping;
use Carbon\Carbon;
use DB;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\QueryBuilder\QueryBuilder;

class SalaryController extends BaseController
{
    /** API */
    public function getSalaries(Request $request)
    {
        try {
            $salaries = QueryBuilder::for(SalaryManager::class)
                ->select('id', 'title', 'start_date', 'end_date', 'date_show', 'total', 'status', 'revision', 'calculator_key', 'published_at', 'created_at', 'updated_at')
                ->allowedFilters('title', 'start_date', 'end_date', 'total', 'created_at', 'updated_at')
                ->defaultSort('-id')
                ->allowedSorts(['id', 'title', 'start_date', 'end_date', 'total', 'created_at', 'updated_at']);

            $limit = $request->limit;
            if (! is_null($limit) && $limit == 0) {
                $limit = $salaries->count();
            }
            $salaries = $salaries->paginate($limit ?? 10);

            return response()->json($salaries);
        } catch (\Throwable $e) {
            return HandleError::handle($e);
        }
    }

    public function getSalary(Request $request, $id)
    {
        try {
            $salary = SalaryManager::query()->findOrFail($id);

            if ($salary->calculator_key) {
                $snapshot = DB::table('payroll_snapshots')->where('salary_id', $id)->first();
                $draft = $snapshot ? json_decode($snapshot->draft, true) : null;
                if ($draft && explode(':', $salary->calculator_key)[0] !== $request->input('company', 'vvp')) {
                    $draft['rows'] = [];
                }

                return response()->json(['category' => [], 'salary' => [], 'salaryDetail' => [], 'attendance' => [], 'snapshot' => $draft, 'manager' => $salary]);
            }

            $company = $request->input('company', 'vvp');
            if (! in_array($company, ['vvp', 'a7a'])) {
                $company = 'vvp';
            }

            $table = $request->input('table', 'category');
            if (! in_array($table, ['category', 'salary', 'salaryDetail', 'attendance'])) {
                $table = 'category';
            }

            $categoryFields = [
                'id',
                'employee_id',
                'salary_day',
                'salary_night',
                'probationary_salary_basic_26days',
                'probationary_salary_basic_hours',
                'probationary_salary_basic_extra_hours',
                'allowance_apprentice',
                'salary_basic',
                'regular_salary_hour',
                'salary_overtime',
                'allowance_diligence',
                'allowance_responsibility',
                'allowance_overtime',
                'allowance_night',
                'allowance_rice',
                'company_insurance',
                'insurance',
            ];

            $salaryFields = [
                'id',
                'employee_id',
                'salary_total',
                'insurance_payroll',
                'advance_money_payroll',
                'company_insurance_payroll',
                'KPI_Subtraction_payroll',
                'previous_period_debt_payroll',
                'actually_received_payroll',
            ];
            $salaryDetailFields = [
                'id',
                'employee_id',
                'number_of_work_days_trial',
                'day_shift_salary_trial',
                'day_shift_salary_trial_notice',
                'number_of_work_nights_trial',
                'night_shift_salary_trial',
                'night_shift_salary_trial_notice',
                'overtime_hours_trial',
                'overtime_salary_trial',
                'overtime_salary_trial_notice',
                'number_of_work',
                'allowance_apprentice_detail',
                'allowance_apprentice_detail_notice',
                'core_hours',
                'official_salary',
                'official_salary_notice',
                'number_of_hours_worked',
                'allowance_diligence_detail',
                'allowance_diligence_detail_notice',
                'allowance_professional_detail',
                'allowance_professional_detail_notice',
                'number_of_jobs',
                'allowance_responsibility_detail',
                'allowance_responsibility_detail_notice',
                'overtime_hours_detail',
                'overtime_salary',
                'overtime_salary_notice',
                'reinforcement_hours_detail',
                'reinforcement_salary',
                'reinforcement_salary_notice',
                'number_of_work_days',
                'allowance_rice_detail',
                'allowance_rice_detail_notice',
                'number_of_work_nights',
                'allowance_shift_night',
                'allowance_shift_night_notice',
                'overtime_day_count_detail',
                'allowance_overtime_detail',
                'allowance_overtime_detail_notice',
                'holidays_count_detail',
                'holidays_money',
                'holidays_money_notice',
                'paid_holidays_count_detail',
                'paid_holidays_money',
                'paid_holidays_money_notice',
                'business_travel_hours',
                'business_travel_unit_price_hour',
                'gcn_business_travel_salary',
                'gcn_business_travel_salary_notice',
                'number_of_business_trips',
                'business_fuel_unit_price_day',
                'allowance_gcn_business_fuel',
                'allowance_gcn_business_fuel_notice',
                'money_referral_people',
                'money_referral_people_notice',
                'allowance_diffrent',
                'allowance_diffrent_notice',
                'bonuses_for_attendance',
                'bonuses_for_attendance_notice',
                'previous_month_kpi_refund',
                'previous_month_kpi_refund_notice',
                'sickness',
                'sickness_notice',
                'funeral',
                'funeral_notice',
                'birthday_money',
                'birthday_money_notice',
                'previous_period_debt',
                'previous_period_debt_notice',
                'total_income',
                'insurance_detail',
                'insurance_detail_notice',
                'advance_money',
                'advance_money_notice',
                'union_fee',
                'union_fee_notice',
                'number_of_violations',

                'daysleave_allowed',
                'daysleave_allowed_notice',
                'subtract_daysleave_allowed',
                'subtract_daysleave_allowed_notice',
                'daysleave_notallowed',
                'daysleave_notallowed_notice',
                'subtract_daysleave_notallowed',
                'subtract_daysleave_notallowed_notice',
                'error_serious',
                'error_serious_notice',
                'subtract_error_serious',
                'subtract_error_serious_notice',
                'error_minor',
                'error_minor_notice',
                'subtract_error_minor',
                'subtract_error_minor_notice',
                'kpi_subtraction',
                'kpi_subtraction_notice',
                'actually_received',
                'forms_of_payment',
                'company_insurance_detail',
            ];

            $attendanceFields = [
                'id',
                'employee_id',
                'total_day_offical',
                'total_night_offical',
                'total_overtime_offical',
                'workday_count_trial',
                'worknight_count_trial',
                'overtime_day_count_trial',
                'allowance_rice_day_timekeeping',
                'allowance_rice_night_timekeeping',
                'allowance_overtime_timekeeping',

                'holidays_count',
                'paid_holidays_count',
                'daysleave_allowed_timekeeping',
                'daysleave_notallowed_timekeeping',
            ];

            if ($company == 'vvp') {
                $companyData = SalaryOfficialVVP::query()->where('salaries_manager_id', $id)
                    ->with([
                        'employee.role:id,role_name',
                        'employee:id,name,role_id',
                    ]);

                $categoryData = $companyData->get($categoryFields);

                $salaryData = $companyData->get($salaryFields);

                $salaryDetailFields = array_merge($salaryDetailFields, [
                    'unicon_deduction',
                    'unicon_deduction_notice',
                ]);

                $salaryDetailData = $companyData->get($salaryDetailFields);

                $attendanceData = $companyData
                    ->with('SalaryOfficialVVPTimekeepings:id,salary_official_vvp_id,timekeeping_date,timekeeping_day,timekeeping_night,timekeeping_overtime')
                    ->get($attendanceFields);

                $data = [
                    'category' => $categoryData,
                    'salary' => $salaryData,
                    'salaryDetail' => $salaryDetailData,
                    'attendance' => $attendanceData,
                ];

                return response()->json($data);
            } elseif ($company == 'a7a') {
                $companyData = SalaryOfficialA7A::query()->where('salaries_manager_id', $id)
                    ->with([
                        'employee.role:id,role_name',
                        'employee:id,name,role_id',
                    ]);

                $categoryData = $companyData->get($categoryFields);
                $salaryData = $companyData->get($salaryFields);
                $salaryDetailFields = array_merge($salaryDetailFields, [
                    'subtract_of_violations',
                    'subtract_of_violations_notice',
                ]);
                $salaryDetailData = $companyData->get($salaryDetailFields);
                $attendanceData = $companyData
                    ->with('SalaryOfficialA7ATimekeepings:id,salary_official_a7a_id,timekeeping_date,timekeeping_day,timekeeping_night,timekeeping_overtime')
                    ->get($attendanceFields);

                $data = [
                    'category' => $categoryData,
                    'salary' => $salaryData,
                    'salaryDetail' => $salaryDetailData,
                    'attendance' => $attendanceData,
                ];

                return response()->json($data);
            }
        } catch (\Throwable $e) {
            return HandleError::handle($e);
        }

    }

    public function addSalary(Request $request)
    {
        DB::beginTransaction();
        try {
            $validated = $request->validate(
                [
                    'title' => 'required|string',
                    'start_date' => 'required|date',
                    'end_date' => 'required|date',
                    'file_vvp' => 'required|mimes:xlsx,xls',
                    'file_a7a' => 'required|mimes:xlsx,xls',
                ]
            );

            $salary = SalaryManager::create([
                'title' => $validated['title'],
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'],
                'date_show' => Carbon::createFromFormat('Y-m-d', $validated['end_date'])
                    ->addMonth()->startOfMonth()->format('Y-m-d'),
            ]);

            if ($request->file('file_vvp')) {
                $excelFileVVP = $request->file('file_vvp')->store('temp');
                Excel::import(new SalaryOfficialVVPManagerImport($salary->id, $salary->start_date, $salary->end_date), $excelFileVVP);
            }
            if ($request->file('file_a7a')) {
                $excelFileA7A = $request->file('file_a7a')->store('temp');
                Excel::import(new SalaryOfficialA7AManagerImport($salary->id, $salary->start_date, $salary->end_date), $excelFileA7A);
            }
            $this->calculateTotal($salary->id);
            DB::commit();

            // Broadcast thông báo đến nhân viên qua Pusher
            $salary->refresh();
            broadcast(new SalaryCreated($salary));

            return response()->json(['message' => 'Salary created successfully!', 'data' => $salary], 201);
        } catch (\Throwable $e) {
            DB::rollBack();

            return HandleError::handle($e);
        }

    }

    public function calculateTotal($id)
    {
        $total = 0;
        $totalVVP = SalaryOfficialVVP::where('salaries_manager_id', $id)->sum('actually_received_payroll');
        $totalA7A = SalaryOfficialA7A::where('salaries_manager_id', $id)->sum('actually_received_payroll');
        // $totalParttime = SalaryParttime::where('salaries_manager_id', $id)->sum('actually_received');
        if ($totalVVP) {
            $total += $totalVVP;
        }
        if ($totalA7A) {
            $total += $totalA7A;
        }
        // if ($totalParttime) {
        //     $total += $totalParttime;
        // }
        $salaryManager = SalaryManager::find($id);
        $salaryManager->total = $total;
        $salaryManager->save();
    }

    public function deleteSalary($id)
    {
        DB::beginTransaction();

        try {
            $salary = SalaryManager::find($id);

            if (! $salary) {
                return response()->json([
                    'error' => [
                        'code' => 404,
                        'message' => 'Salary not found',
                    ],
                ], 404);
            }

            $salaryOfficialsVVP = SalaryOfficialVVP::where('salaries_manager_id', $id)->get();
            $salaryOfficialsA7A = SalaryOfficialA7A::where('salaries_manager_id', $id)->get();
            // $salaryParttimes = SalaryParttime::where('salaries_manager_id', $id)->get();
            foreach ($salaryOfficialsVVP as $salaryOfficialVVP) {
                SalaryOfficialVVPTimekeeping::where('salary_official_vvp_id', $salaryOfficialVVP->id)->delete();
                SalaryOfficialVVP::where('id', $salaryOfficialVVP->id)->delete();
            }
            foreach ($salaryOfficialsA7A as $salaryOfficialA7A) {
                SalaryOfficialA7ATimekeeping::where('salary_official_a7a_id', $salaryOfficialA7A->id)->delete();
                SalaryOfficialA7A::where('id', $salaryOfficialA7A->id)->delete();
            }

            $salary->delete();

            DB::commit();

            return response()->json(['message' => 'Salary deleted successfully'], 200);
        } catch (\Throwable $e) {
            DB::rollBack();

            return HandleError::handle($e);
        }

    }
}
