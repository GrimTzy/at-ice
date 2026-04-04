<?php

namespace App\Http\Controllers;

use App\Models\LeaveRequest;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class LeaveRequestController extends Controller
{
    // GET /api/leave-requests
    public function index(Request $request)
    {
        $query = LeaveRequest::with('employee');

        if ($request->has('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        return response()->json($query->orderBy('created_at', 'desc')->get(), 200);
    }

    // POST /api/leave-requests
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'employee_id'  => 'required|exists:employees,id',
            'leave_type'   => 'required|in:sick,vacation,emergency,others',
            'start_date'   => 'required|date',
            'end_date'     => 'required|date|after_or_equal:start_date',
            'reason'       => 'required|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $totalDays = Carbon::parse($request->start_date)->diffInDays($request->end_date) + 1;

        $leave = LeaveRequest::create([
            'employee_id' => $request->employee_id,
            'leave_type'  => $request->leave_type,
            'start_date'  => $request->start_date,
            'end_date'    => $request->end_date,
            'total_days'  => $totalDays,
            'reason'      => $request->reason,
            'status'      => 'pending',
        ]);

        return response()->json(['message' => 'Leave request submitted.', 'data' => $leave], 201);
    }

    // GET /api/leave-requests/{id}
    public function show($id)
    {
        $leave = LeaveRequest::with('employee')->find($id);

        if (!$leave) {
            return response()->json(['message' => 'Leave request not found.'], 404);
        }

        return response()->json($leave, 200);
    }

    // PUT /api/leave-requests/{id}/status
    public function updateStatus(Request $request, $id)
    {
        $leave = LeaveRequest::find($id);

        if (!$leave) {
            return response()->json(['message' => 'Leave request not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'status'  => 'required|in:approved,rejected',
            'remarks' => 'nullable|string|max:300',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $leave->update([
            'status'  => $request->status,
            'remarks' => $request->remarks ?? null,
        ]);

        return response()->json(['message' => 'Leave request updated.', 'data' => $leave], 200);
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