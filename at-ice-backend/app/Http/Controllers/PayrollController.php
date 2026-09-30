<?php

namespace App\Http\Controllers;

use App\Models\Payroll;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PayrollController extends Controller
{
    // GET /api/payrolls
    // Optional filters: ?employee_id=, ?status=
    public function index(Request $request)
    {
        $query = Payroll::with(['employee', 'preparedBy:id,name']);

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return response()->json(
            $query->orderBy('period_start', 'desc')->orderBy('created_at', 'desc')->get(),
            200
        );
    }

    // POST /api/payrolls
    // The computation (Sweldong Pinoy reference: SSS, PhilHealth, Pag-IBIG,
    // withholding tax) happens on the frontend. This endpoint stores the
    // computed values.
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

        $status = $request->status ?? 'pending';

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
            'status'               => $status,
            'prepared_by'          => auth()->id(),
            'paid_at'              => $status === 'paid' ? now() : null,
            'notes'                => $request->notes ?? null,
        ]);

        return response()->json(['message' => 'Payroll record created.', 'data' => $payroll], 201);
    }

    // GET /api/payrolls/{id}
    public function show($id)
    {
        $payroll = Payroll::with(['employee', 'preparedBy:id,name'])->find($id);

        if (!$payroll) {
            return response()->json(['message' => 'Payroll not found.'], 404);
        }

        return response()->json($payroll, 200);
    }

    // PUT /api/payrolls/{id}
    // Also used by the Payroll page for status changes
    // (pending -> approved -> paid). paid_at is set when marked paid.
    public function update(Request $request, $id)
    {
        $payroll = Payroll::find($id);

        if (!$payroll) {
            return response()->json(['message' => 'Payroll not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'period_start'         => 'sometimes|date',
            'period_end'           => 'sometimes|date',
            'days_worked'          => 'sometimes|numeric|min:0',
            'daily_rate'           => 'sometimes|numeric|min:0',
            'gross_pay'            => 'sometimes|numeric|min:0',
            'sss_deduction'        => 'sometimes|nullable|numeric|min:0',
            'philhealth_deduction' => 'sometimes|nullable|numeric|min:0',
            'pagibig_deduction'    => 'sometimes|nullable|numeric|min:0',
            'tax_deduction'        => 'sometimes|nullable|numeric|min:0',
            'other_deductions'     => 'sometimes|nullable|numeric|min:0',
            'net_pay'              => 'sometimes|numeric|min:0',
            'status'               => 'sometimes|in:pending,approved,paid',
            'notes'                => 'sometimes|nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $request->only([
            'period_start', 'period_end', 'days_worked', 'daily_rate',
            'gross_pay', 'sss_deduction', 'philhealth_deduction',
            'pagibig_deduction', 'tax_deduction', 'other_deductions',
            'net_pay', 'status', 'notes'
        ]);

        if ($request->has('status')) {
            $data['paid_at'] = $request->status === 'paid'
                ? ($payroll->paid_at ?? now())
                : null;
        }

        $payroll->update($data);

        return response()->json([
            'message' => 'Payroll updated.',
            'data'    => $payroll->fresh(['employee', 'preparedBy:id,name']),
        ], 200);
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