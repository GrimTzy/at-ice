<?php

namespace App\Http\Controllers;

use App\Events\AttendanceRecorded;
use App\Models\Attendance;
use App\Models\FaceData;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    // An open Time In older than this is treated as a missed Time Out and
    // is no longer matched by a new scan.
    private const MAX_SHIFT_HOURS = 16;

    public function index(Request $request)
    {
        $request->validate([
            'date' => 'nullable|date',
        ]);

        $attendances = Attendance::with([
                'employee',
                'recordedBy',
                'faceData:id,worker_name,role,employee_id',
            ])
            ->when($request->filled('date'), function ($query) use ($request) {
                $query->whereDate('date', $request->date);
            })
            ->orderBy('date', 'desc')
            ->orderBy('time_in', 'asc')
            ->get();

        return response()->json($attendances);
    }

    // POST /api/attendances (public, used by the Attendance Station kiosk)
    // The kiosk now sends which button was selected: "time_in" or
    // "time_out". Date and time always come from the server clock
    // (Asia/Manila), never from the browser.
    public function store(Request $request)
    {
        $request->validate([
            'face_data_id' => 'required|exists:face_data,id',
            'type'         => 'required|in:time_in,time_out',
        ]);

        $faceData = FaceData::select('id', 'worker_name', 'role', 'employee_id')
            ->findOrFail($request->face_data_id);

        $now   = now();
        $today = $now->toDateString();

        // Open record: today's, or yesterday's for a night shift
        $open = Attendance::where('face_data_id', $faceData->id)
            ->whereNull('time_out')
            ->whereDate('date', '>=', $now->copy()->subDay()->toDateString())
            ->orderByDesc('date')
            ->first();

        $openIn = null;
        if ($open) {
            $openIn = Carbon::parse(
                Carbon::parse($open->date)->toDateString() . ' ' . $open->time_in
            );
            // Too long ago: treat as a missed Time Out, not an open shift
            if ($openIn->diffInHours($now, true) > self::MAX_SHIFT_HOURS) {
                $open   = null;
                $openIn = null;
            }
        }

        // Worker already has a complete Time In and Time Out today
        $doneToday = Attendance::where('face_data_id', $faceData->id)
            ->whereDate('date', $today)
            ->whereNotNull('time_out')
            ->exists();

        // TIME OUT
        if ($request->type === 'time_out') {
            if (!$open) {
                if ($doneToday) {
                    return $this->reject($faceData, 'completed',
                        'Time In and Time Out are already recorded for today.');
                }
                return $this->reject($faceData, 'no_time_in',
                    'No Time In found. Select Time In first, or inform HR.');
            }

            $minutes = $openIn->diffInMinutes($now, true);
            $hours   = round($minutes / 60, 2);

            $open->update([
                'time_out'     => $now->format('H:i:s'),
                'hours_worked' => $hours,
            ]);

            $this->notify($faceData, $open, 'time_out',
                "{$faceData->worker_name} timed out at {$now->format('g:i A')} ({$hours} hrs).");

            return response()->json([
                'type'         => 'time_out',
                'message'      => 'Time Out recorded.',
                'worker'       => $faceData->worker_name,
                'role'         => $faceData->role,
                'hours_worked' => $hours,
                'attendance'   => $open,
            ], 200);
        }

        // TIME IN
        if ($open) {
            return $this->reject($faceData, 'already_timed_in',
                'Time In already recorded. Select Time Out at the end of your shift.');
        }

        if ($doneToday) {
            return $this->reject($faceData, 'completed',
                'Time In and Time Out are already recorded for today.');
        }

        $attendance = Attendance::create([
            'face_data_id' => $faceData->id,
            'employee_id'  => $faceData->employee_id,
            'recorded_by'  => null,
            'date'         => $today,
            'time_in'      => $now->format('H:i:s'),
            'time_out'     => null,
            'hours_worked' => 0,
            'status'       => 'present',
            'notes'        => null,
        ]);

        $this->notify($faceData, $attendance, 'time_in',
            "{$faceData->worker_name} timed in at {$now->format('g:i A')}.");

        return response()->json([
            'type'       => 'time_in',
            'message'    => 'Time In recorded.',
            'worker'     => $faceData->worker_name,
            'role'       => $faceData->role,
            'attendance' => $attendance,
        ], 201);
    }

    // A scan that is understood but not recorded (wrong button, already done)
    private function reject(FaceData $faceData, string $type, string $message)
    {
        return response()->json([
            'type'    => $type,
            'message' => $message,
            'worker'  => $faceData->worker_name,
            'role'    => $faceData->role,
        ], 409);
    }

    // Live notification for HR and the Admin dashboard (Pusher)
    private function notify(FaceData $faceData, Attendance $attendance, string $type, string $message): void
    {
        try {
            broadcast(new AttendanceRecorded([
                'face_data_id' => $attendance->face_data_id,
                'employee_id'  => $attendance->employee_id,
                'worker'       => $faceData->worker_name,
                'type'         => $type,
                'date'         => $attendance->date,
                'time_in'      => $attendance->time_in,
                'time_out'     => $attendance->time_out,
                'status'       => $attendance->status,
                'message'      => $message,
            ]))->toOthers();
        } catch (\Throwable $e) {
            // The attendance is already saved; a broadcast failure must not
            // turn a successful scan into an error at the kiosk.
            report($e);
        }
    }

    // PUT update attendance (HR correction)
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
            'message'    => 'Attendance updated successfully!',
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