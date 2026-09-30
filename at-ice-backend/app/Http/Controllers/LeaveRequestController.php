<?php

namespace App\Http\Controllers;

use App\Models\LeaveRequest;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class LeaveRequestController extends Controller
{
    private const LEAVE_TYPES = 'sick,vacation,emergency,maternity,paternity,other';

    // GET /api/leave-requests
    // Optional filters: ?employee_id=, ?status=
    public function index(Request $request)
    {
        $query = LeaveRequest::with(['employee', 'approvedBy:id,name']);

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return response()->json($query->orderBy('created_at', 'desc')->get(), 200);
    }

    // POST /api/leave-requests
    // HR encodes the leave on behalf of the employee (verbal or paper request).
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'employee_id' => 'required|exists:employees,id',
            'leave_type'  => 'required|in:' . self::LEAVE_TYPES,
            'start_date'  => 'required|date',
            'end_date'    => 'required|date|after_or_equal:start_date',
            'reason'      => 'required|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $totalDays = (int) Carbon::parse($request->start_date)
            ->startOfDay()
            ->diffInDays(Carbon::parse($request->end_date)->startOfDay()) + 1;

        $leave = LeaveRequest::create([
            'employee_id' => $request->employee_id,
            'leave_type'  => $request->leave_type,
            'start_date'  => $request->start_date,
            'end_date'    => $request->end_date,
            'total_days'  => $totalDays,
            'reason'      => $request->reason,
            'status'      => 'pending',
        ]);

        return response()->json([
            'message' => 'Leave request submitted.',
            'data'    => $leave->load(['employee', 'approvedBy:id,name']),
        ], 201);
    }

    // GET /api/leave-requests/{id}
    public function show($id)
    {
        $leave = LeaveRequest::with(['employee', 'approvedBy:id,name'])->find($id);

        if (!$leave) {
            return response()->json(['message' => 'Leave request not found.'], 404);
        }

        return response()->json($leave, 200);
    }

    // PUT /api/leave-requests/{id}/status
    // Approve or reject a PENDING request. approved_by / approved_at record
    // who decided and when, for both approve and reject.
    public function updateStatus(Request $request, $id)
    {
        $leave = LeaveRequest::find($id);

        if (!$leave) {
            return response()->json(['message' => 'Leave request not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'status'      => 'required|in:approved,rejected',
            'admin_notes' => 'nullable|string|max:300',
            'remarks'     => 'nullable|string|max:300', // older field name, still accepted
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if ($leave->status !== 'pending') {
            return response()->json([
                'message' => "This leave request was already {$leave->status}.",
            ], 422);
        }

        $leave->update([
            'status'      => $request->status,
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
            'admin_notes' => $request->input('admin_notes', $request->input('remarks')),
        ]);

        return response()->json([
            'message' => 'Leave request updated.',
            'data'    => $leave->load(['employee', 'approvedBy:id,name']),
        ], 200);
    }

    // DELETE /api/leave-requests/{id}
    public function destroy($id)
    {
        $leave = LeaveRequest::find($id);

        if (!$leave) {
            return response()->json(['message' => 'Leave request not found.'], 404);
        }

        if ($leave->status !== 'pending') {
            return response()->json(['message' => 'Only pending requests can be cancelled.'], 403);
        }

        $leave->delete();

        return response()->json(['message' => 'Leave request cancelled.'], 200);
    }
}