<?php

namespace App\Http\Controllers;

use App\Models\Payroll;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PayrollController extends Controller
{
    // GET /api/payrolls
    public function index(Request $request)
    {
        $query = Payroll::with('employee');

        if ($request->has('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }

        return response()->json($query->orderBy('created_at', 'desc')->get(), 200);
    }

    // POST /api/payrolls
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'employee_id'          => 'required|exists:employees,id',
            'period_start'         => 'required|date',
            'period_end'           => 'required|date|after_or_equal:period_start',
            'days_worked'          => 'required|numeric|min:0',
            'daily_rate'           => 'required|numeric|min:0',
            'gross_pay'            => 'required|numeric|min:0',
            'sss_deduction'        => 'nullable|numeric|min:0',
            'philhealth_deduction' => 'nullable|numeric|min:0',
            'pagibig_deduction'    => 'nullable|numeric|min:0',
            'tax_deduction'        => 'nullable|numeric|min:0',
            'other_deductions'     => 'nullable|numeric|min:0',
            'net_pay'              => 'required|numeric|min:0',
            'status'               => 'nullable|in:pending,approved,paid',
            'notes'                => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $payroll = Payroll::create([
            'employee_id'          => $request->employee_id,
            'period_start'         => $request->period_start,
            'period_end'           => $request->period_end,
            'days_worked'          => $request->days_worked,
            'daily_rate'           => $request->daily_rate,
            'gross_pay'            => $request->gross_pay,
            'sss_deduction'        => $request->sss_deduction ?? 0,
            'philhealth_deduction' => $request->philhealth_deduction ?? 0,
            'pagibig_deduction'    => $request->pagibig_deduction ?? 0,
            'tax_deduction'        => $request->tax_deduction ?? 0,
            'other_deductions'     => $request->other_deductions ?? 0,
            'net_pay'              => $request->net_pay,
            'status'               => $request->status ?? 'pending',
            'prepared_by'          => $request->prepared_by ?? 1,
            'notes'                => $request->notes ?? null,
        ]);

        return response()->json(['message' => 'Payroll record created.', 'data' => $payroll], 201);
    }

    // GET /api/payrolls/{id}
    public function show($id)
    {
        $payroll = Payroll::with('employee')->find($id);

        if (!$payroll) {
            return response()->json(['message' => 'Payroll not found.'], 404);
        }

        return response()->json($payroll, 200);
    }

    // PUT /api/payrolls/{id}
    public function update(Request $request, $id)
    {
        $payroll = Payroll::find($id);

        if (!$payroll) {
            return response()->json(['message' => 'Payroll not found.'], 404);
        }

        $payroll->update($request->only([
            'period_start', 'period_end', 'days_worked', 'daily_rate',
            'gross_pay', 'sss_deduction', 'philhealth_deduction',
            'pagibig_deduction', 'tax_deduction', 'other_deductions',
            'net_pay', 'status', 'notes'
        ]));

        return response()->json(['message' => 'Payroll updated.', 'data' => $payroll], 200);
    }

    // DELETE /api/payrolls/{id}
    public function destroy($id)
    {
        $payroll = Payroll::find($id);

        if (!$payroll) {
            return response()->json(['message' => 'Payroll not found.'], 404);
        }

        $payroll->delete();

        return response()->json(['message' => 'Payroll record deleted.'], 200);
    }
}