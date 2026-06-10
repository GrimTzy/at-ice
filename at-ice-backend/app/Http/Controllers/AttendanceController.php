<?php

namespace App\Http\Controllers;

use App\Events\AttendanceRecorded;
use App\Models\Attendance;
use App\Models\Employee;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    // GET all attendances
    public function index(Request $request)
    {
        $attendances = Attendance::with('employee', 'recordedBy')
            ->orderBy('date', 'desc')
            ->get();
        return response()->json($attendances);
    }

    // POST record attendance
    public function store(Request $request)
    {
        $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'date' => 'required|date',
            'time_in' => 'required',
            'status' => 'required|in:present,absent,late,half_day,on_leave',
        ]);

        // Check if attendance already recorded for this date
        $existing = Attendance::where('employee_id', $request->employee_id)
            ->where('date', $request->date)
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'Attendance already recorded for this date!'
            ], 422);
        }

        $attendance = Attendance::create([
            'employee_id' => $request->employee_id,
            'recorded_by' => auth()->id(),
            'date' => $request->date,
            'time_in' => $request->time_in,
            'time_out' => $request->time_out,
            'hours_worked' => $request->hours_worked ?? 0,
            'status' => $request->status,
            'notes' => $request->notes,
        ]);

        // Fire AttendanceRecorded event
        broadcast(new AttendanceRecorded([
            'employee_id' => $attendance->employee_id,
            'date' => $attendance->date,
            'time_in' => $attendance->time_in,
            'status' => $attendance->status,
            'message' => 'Attendance recorded successfully!',
        ]))->toOthers();

        return response()->json([
            'message' => 'Attendance recorded successfully!',
            'attendance' => $attendance
        ], 201);
    }

    // PUT update attendance (time out)
    public function update(Request $request, $id)
    {
        $attendance = Attendance::findOrFail($id);

        $attendance->update($request->only([
            'time_out',
            'hours_worked',
            'status',
            'notes',
        ]));

        return response()->json([
            'message' => 'Attendance updated successfully!',
            'attendance' => $attendance
        ]);
    }

    // GET attendance by employee
    public function getByEmployee($employee_id)
    {
        $attendances = Attendance::with('recordedBy')
            ->where('employee_id', $employee_id)
            ->orderBy('date', 'desc')
            ->get();
        return response()->json($attendances);
    }
}