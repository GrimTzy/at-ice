<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Support\Audit;
use App\Support\LeaveCredits;
use App\Support\Settings;
use App\Support\StoredFiles;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

// Leave (docs/REVIEW-DECISIONS.md, QA review 2026-11-11). Employees file their
// own leave; HR only approves or rejects (a rejection needs a reason).
//   VL  vacation leave, starting at least leave.vl_notice_days ahead
//   EL  emergency leave, filed in hours for one day; uses the VL credits
//   SL  sick leave, for past dates only, filed at most
//       leave.sl_filing_window_days after returning; needs SL credits and a
//       medical certificate. Never approved on its own.
//   These three are blocked while probationary. The leave the law gives
//   (LeaveRequest::STATUTORY: maternity, paternity, solo parent, VAWC, special
//   leave for women) needs no credits and no regular status, only its own
//   conditions and proof, and is paid in full. No overlapping requests.
// When HR approves, VL, EL and SL are paid from their own credits as far as
// they go (LeaveCredits); the rest is unpaid and is deducted in payroll and
// the 13th month. HR may mark an SL unpaid.
class LeaveRequestController extends Controller
{
    private function types(): string
    {
        return implode(',', array_keys(LeaveRequest::TYPE_LABELS));
    }

    private function today(): Carbon
    {
        return Carbon::now(config('atice.timezone'))->startOfDay();
    }

    // Number of days: 0.5 for a half day, otherwise the work days from start to
    // end, leaving out holidays (no leave is needed on a holiday, R3) and rest
    // days. Maternity and the special leave for women count calendar days.
    private function computeDays(string $start, string $end, bool $half, bool $calendar = false): float
    {
        if ($half) {
            return 0.5;
        }
        if ($calendar) {
            return (float) (Carbon::parse($start)->diffInDays(Carbon::parse($end)) + 1);
        }
        $workDays = array_map('intval', explode(',', (string) Settings::get('attendance.work_days')));
        $days = 0;
        foreach (CarbonPeriod::create($start, $end) as $d) {
            if (in_array($d->dayOfWeekIso, $workDays, true) && ! Holiday::forDate($d->toDateString())) {
                $days++;
            }
        }

        return (float) $days;
    }

    // The Employee row linked to the logged in user (employees.user_id).
    private function myEmployee(Request $request): ?Employee
    {
        return Employee::where('user_id', $request->user()->id)
            ->where('is_active', true)
            ->first();
    }

    // A pending or approved leave of this employee that shares a day with start..end.
    private function overlaps(int $employeeId, string $start, string $end): bool
    {
        return LeaveRequest::where('employee_id', $employeeId)
            ->whereIn('status', ['pending', 'approved'])
            ->whereDate('start_date', '<=', $end)
            ->whereDate('end_date', '>=', $start)
            ->exists();
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }

    // HR SIDE

    // GET /api/leave-requests?status=&employee_id=  (with each employee's credits)
    public function index(Request $request)
    {
        $leaves = LeaveRequest::with(['employee', 'approvedBy:id,name'])
            ->when($request->filled('employee_id'), fn ($q) => $q->where('employee_id', $request->employee_id))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->orderBy('created_at', 'desc')
            ->get();

        $credits = [];
        foreach ($leaves as $leave) {
            if ($leave->employee && ! isset($credits[$leave->employee_id])) {
                $credits[$leave->employee_id] = LeaveCredits::for($leave->employee);
            }
            $leave->setAttribute('credits', $credits[$leave->employee_id] ?? null);
            $leave->setAttribute('has_attachment', (bool) $leave->attachment_path);
        }

        return response()->json($leaves, 200);
    }

    // GET /api/leave-requests/{id}
    public function show($id)
    {
        $leave = LeaveRequest::with(['employee', 'approvedBy:id,name'])->find($id);

        if (! $leave) {
            return response()->json(['message' => 'Leave request not found.'], 404);
        }

        return response()->json($leave, 200);
    }

    // PUT /api/leave-requests/{id}/status
    //   { status: "approved", remarks?, is_paid? (SL only) }
    //   { status: "rejected", reject_reason }
    public function updateStatus(Request $request, $id)
    {
        $leave = LeaveRequest::with('employee')->find($id);

        if (! $leave) {
            return response()->json(['message' => 'Leave request not found.'], 404);
        }

        $data = $request->validate([
            'status' => 'required|in:approved,rejected',
            'reject_reason' => 'required_if:status,rejected|nullable|string|min:3|max:300',
            'remarks' => 'nullable|string|max:300',
            'admin_notes' => 'nullable|string|max:300', // older field name, still accepted
            'is_paid' => 'nullable|boolean',
        ], [
            'reject_reason.required_if' => 'Give the reason for rejecting this leave.',
        ]);

        if ($leave->status !== 'pending') {
            return response()->json(['message' => "This leave request was already {$leave->status}."], 422);
        }

        $update = [
            'status' => $data['status'],
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
            'admin_notes' => $data['remarks'] ?? $data['admin_notes'] ?? null,
        ];

        if ($data['status'] === 'rejected') {
            $update['reject_reason'] = $data['reject_reason'];
        } else {
            // VL, EL, SL: paid from their own credits as far as they go, the
            // rest unpaid. Leave the law gives is paid in full.
            $days = LeaveCredits::days($leave);
            $pool = LeaveCredits::pool($leave->leave_type);
            $available = $pool === null ? $days
                : ($leave->employee ? LeaveCredits::for($leave->employee, $leave->start_date->toDateString())[$pool]['available'] : 0);
            $wantsPaid = $leave->leave_type !== 'sick' || $request->boolean('is_paid', true);
            $paid = $wantsPaid ? min($days, $available) : 0;
            $update += [
                'paid_days' => round($paid, 2),
                'unpaid_days' => round($days - $paid, 2),
                'is_paid' => $paid >= $days,
            ];
        }

        $before = ['status' => $leave->status];
        $leave->update($update);
        Audit::log('leave.'.$data['status'], $leave, $before, array_intersect_key($update, array_flip(['status', 'reject_reason', 'admin_notes', 'paid_days', 'unpaid_days', 'is_paid'])));

        return response()->json([
            'message' => 'Leave request updated.',
            'data' => $leave->load(['employee', 'approvedBy:id,name']),
        ], 200);
    }

    // DELETE /api/leave-requests/{id} (pending only)
    public function destroy($id)
    {
        $leave = LeaveRequest::find($id);

        if (! $leave) {
            return response()->json(['message' => 'Leave request not found.'], 404);
        }

        if ($leave->status !== 'pending') {
            return response()->json(['message' => 'Only pending requests can be cancelled.'], 403);
        }

        $leave->delete();
        $this->deleteAttachment($leave); // after the record change; the file goes once it is committed

        return response()->json(['message' => 'Leave request cancelled.'], 200);
    }

    // GET /api/leave-requests/{id}/attachment : the med cert or other file (HR)
    public function attachment($id)
    {
        return $this->sendAttachment(LeaveRequest::findOrFail($id));
    }

    // EMPLOYEE / DRIVER SIDE (own requests only)

    // GET /api/my-leave-requests
    public function myIndex(Request $request)
    {
        $employee = $this->myEmployee($request);

        // A login with no employee record simply has no requests, not an error.
        if (! $employee) {
            return response()->json([]);
        }

        $leaves = LeaveRequest::with('approvedBy:id,name')
            ->where('employee_id', $employee->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($leaves, 200);
    }

    // GET /api/my-leave-credits : credits and the filing rules for the form
    public function myCredits(Request $request)
    {
        $employee = $this->myEmployee($request);
        if (! $employee) {
            return response()->json(['employee_linked' => false]);
        }

        return response()->json(LeaveCredits::for($employee) + [
            'probationary' => $employee->employment_status !== 'regular',
            'hours_per_day' => (float) Settings::get('payroll.hours_per_day'),
            'sl_filing_window_days' => (int) Settings::get('leave.sl_filing_window_days'),
            'vl_notice_days' => (int) Settings::get('leave.vl_notice_days'),
            'work_days' => array_map('intval', explode(',', (string) Settings::get('attendance.work_days'))),
            // the leave the law gives that this employee can file, with its rules
            'statutory' => collect(LeaveRequest::STATUTORY)
                ->filter(fn ($rule, $type) => $this->statutoryBlock($employee, $type, now(config('atice.timezone'))) === null)
                ->map(fn ($rule, $type) => ['label' => LeaveRequest::TYPE_LABELS[$type]] + $rule)->all(),
        ]);
    }

    // Why this employee cannot take this leave the law gives (on that date), or null.
    private function statutoryBlock(Employee $employee, string $type, Carbon $on): ?string
    {
        $rule = LeaveRequest::STATUTORY[$type];
        $label = LeaveRequest::TYPE_LABELS[$type];
        if ($rule['sex'] && $employee->sex !== $rule['sex']) {
            return $employee->sex
                ? "{$label} is for {$rule['sex']} employees ({$rule['law']})."
                : "{$label} is for {$rule['sex']} employees ({$rule['law']}). Ask HR to fill in your sex in your biodata first.";
        }
        if ($rule['service'] && Carbon::parse($employee->date_hired)->addMonths($rule['service'])->gt($on)) {
            return "{$label} needs {$rule['service']} months of service first ({$rule['law']}).";
        }

        return null;
    }

    // GET /api/leave-balances (HR): every active employee's VL and SL credits
    // this year, the same numbers as their own my-leave-credits (LeaveCredits),
    // with the days used split by leave type (leave the law gives included).
    public function balances()
    {
        $year = now(config('atice.timezone'))->year;
        $usedByType = LeaveRequest::where('status', 'approved')
            ->whereYear('start_date', $year)
            ->selectRaw('employee_id, leave_type, SUM(paid_days) as days')
            ->groupBy('employee_id', 'leave_type')
            ->get()
            ->groupBy('employee_id');

        $rows = Employee::where('is_active', true)
            ->orderBy('last_name')->orderBy('first_name')
            ->get()
            ->map(function (Employee $e) use ($usedByType) {
                $credits = LeaveCredits::for($e);
                $types = $usedByType->get($e->id, collect())->pluck('days', 'leave_type');

                return [
                    'id' => $e->id,
                    'employee_id' => $e->employee_id,
                    'name' => $e->full_name,
                    'position' => $e->position,
                    'probationary' => $e->employment_status !== 'regular',
                    'earned' => $credits['earned'],
                    'used' => $credits['used'],
                    'available' => $credits['available'],
                    'vacation' => $credits['vacation'],
                    'sick' => $credits['sick'],
                    'used_by_type' => collect(array_keys(LeaveRequest::TYPE_LABELS))
                        ->mapWithKeys(fn ($t) => [$t => round((float) ($types[$t] ?? 0), 2)])->all(),
                ];
            });

        return response()->json(['year' => $year, 'types' => LeaveRequest::TYPE_LABELS, 'employees' => $rows]);
    }

    // POST /api/my-leave-requests (multipart/form-data; the med cert is "attachment")
    public function myStore(Request $request)
    {
        $employee = $this->myEmployee($request);

        if (! $employee) {
            return response()->json(['message' => 'No employee record linked to this login.'], 404);
        }

        $request->validate([
            'leave_type' => 'required|in:'.$this->types(),
            'start_date' => 'required|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d|after_or_equal:start_date',
            'is_half_day' => 'nullable|boolean',
            'hours' => 'required_if:leave_type,emergency|nullable|numeric|min:0.5|max:'.(float) Settings::get('payroll.hours_per_day'),
            'reason' => 'required|string|max:500',
            'attachment' => 'nullable|file|mimes:png,jpg,jpeg,pdf|max:5120',
        ], [
            'hours.required_if' => 'Enter how many hours of emergency leave.',
        ]);

        $type = $request->leave_type;
        $statutory = LeaveRequest::STATUTORY[$type] ?? null;
        $label = LeaveRequest::TYPE_LABELS[$type];
        $today = $this->today();
        $start = Carbon::parse($request->start_date);
        $half = ! $statutory && $type !== 'emergency' && $request->boolean('is_half_day');
        $end = ($type === 'emergency' || $half) ? $start->copy() : Carbon::parse($request->end_date ?? $request->start_date);

        // VL, EL and SL come from credits, which start once regular. The leave
        // the law gives does not wait for that.
        if (! $statutory && $employee->employment_status !== 'regular') {
            $this->fail('leave_type', 'Vacation, emergency and sick leave cannot be filed while probationary. Absences during probation are unpaid.');
        }
        if ($statutory && ($why = $this->statutoryBlock($employee, $type, $start))) {
            $this->fail('leave_type', $why);
        }

        // No leave from before the hire date, nor more than a year ahead
        if ($start->lt(Carbon::parse($employee->date_hired)->startOfDay())) {
            $this->fail('start_date', 'The leave cannot start before your hire date ('.Carbon::parse($employee->date_hired)->format('M j, Y').').');
        }
        if ($end->gt($today->copy()->addYear())) {
            $this->fail('end_date', 'Leave can be filed at most one year ahead.');
        }

        // Holidays need no leave: a request only on holidays is refused, and
        // holidays inside a longer request are not counted (computeDays).
        $calendar = (bool) ($statutory['calendar'] ?? false);
        if (! $calendar) {
            if ($type === 'emergency' || $half || $start->equalTo($end)) {
                if ($h = Holiday::forDate($start->toDateString())) {
                    $this->fail('start_date', "{$start->format('M j, Y')} is a holiday ({$h->name}). No leave is needed: you are not marked absent on a holiday.");
                }
            }
            if ($type !== 'emergency' && ! $half && $this->computeDays($start->toDateString(), $end->toDateString(), false) === 0.0) {
                $this->fail('start_date', 'Every day in this range is a rest day or a holiday. No leave is needed.');
            }
        }

        $credits = LeaveCredits::for($employee);
        $pool = LeaveCredits::pool($type);
        $days = $type === 'emergency'
            ? (float) $request->hours / (float) Settings::get('payroll.hours_per_day')
            : $this->computeDays($start->toDateString(), $end->toDateString(), $half, $calendar);

        if ($statutory) {
            $unit = $calendar ? 'days' : 'work days';
            if ($days > $statutory['days']) {
                $this->fail('end_date', "{$label} is at most {$statutory['days']} {$unit} ({$statutory['law']}). This request is {$days}.");
            }
            if ($statutory['per_year']) {
                $taken = (float) LeaveRequest::where('employee_id', $employee->id)->where('leave_type', $type)
                    ->whereIn('status', ['pending', 'approved'])->whereYear('start_date', $start->year)->sum('total_days');
                if ($taken + $days > $statutory['per_year']) {
                    $this->fail('end_date', "{$label} is at most {$statutory['per_year']} {$unit} a year ({$statutory['law']}). {$taken} already filed in {$start->year}.");
                }
            }
            if (! $request->hasFile('attachment')) {
                $this->fail('attachment', "Attach the proof for {$label}: {$statutory['proof']}.");
            }
        }

        if ($type === 'sick') {
            $window = (int) Settings::get('leave.sl_filing_window_days');
            if ($end->gte($today)) {
                $this->fail('start_date', 'Sick leave is filed after you return, for past dates only.');
            }
            if ($end->lt($today->copy()->subDays($window))) {
                $this->fail('start_date', "Sick leave must be filed at most {$window} day".($window === 1 ? '' : 's').' after you return.');
            }
            if (! $request->hasFile('attachment')) {
                $this->fail('attachment', 'Attach your medical certificate.');
            }
            if ($credits['sick']['available'] < $days) {
                $this->fail('leave_type', "Not enough sick leave credits ({$credits['sick']['available']} day(s) left).");
            }
        }
        if ($type === 'vacation') {
            $notice = (int) Settings::get('leave.vl_notice_days');
            if ($start->lt($today->copy()->addDays($notice))) {
                $this->fail('start_date', "Vacation leave must start at least {$notice} days from today (on or after {$today->copy()->addDays($notice)->format('M j, Y')}).");
            }
        }

        if ($this->overlaps($employee->id, $start->toDateString(), $end->toDateString())) {
            $this->fail('start_date', 'You already have a pending or approved leave on those dates.');
        }

        // Kept private: only HR and the employee can open it (attachment routes).
        // The file is written before the record: if saving fails the file goes too.
        $stored = new StoredFiles('local');
        try {
            $path = $request->hasFile('attachment') ? $stored->store($request->file('attachment'), 'leave-attachments') : null;

            $leave = LeaveRequest::create([
                'employee_id' => $employee->id,
                'leave_type' => $type,
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'total_days' => $type === 'emergency' ? round($days, 2) : $days,
                'hours' => $type === 'emergency' ? $request->hours : null,
                'is_half_day' => $half,
                'reason' => $request->reason,
                'status' => 'pending',
                'attachment_path' => $path,
                'attachment_disk' => 'local',
            ]);
        } catch (\Throwable $e) {
            $stored->discard();
            throw $e;
        }

        return response()->json([
            'message' => $statutory
                ? "Leave request sent. {$label} is given by law ({$statutory['law']}) and is paid in full once HR approves it."
                : ($credits[$pool]['available'] >= $days
                    ? 'Leave request sent. It will be paid from your '.($pool === 'sick' ? 'sick' : 'vacation').' leave credits once HR approves it.'
                    : 'Leave request sent. You do not have enough '.($pool === 'sick' ? 'sick' : 'vacation').' leave credits, so the rest will be unpaid.'),
            'data' => $leave->load('approvedBy:id,name'),
            'credits' => $credits,
        ], 201);
    }

    // DELETE /api/my-leave-requests/{id} (own, pending only)
    public function myDestroy(Request $request, $id)
    {
        $employee = $this->myEmployee($request);

        if (! $employee) {
            return response()->json(['message' => 'No employee record linked to this login.'], 404);
        }

        $leave = LeaveRequest::where('employee_id', $employee->id)->find($id);

        if (! $leave) {
            return response()->json(['message' => 'Leave request not found.'], 404);
        }

        if ($leave->status !== 'pending') {
            return response()->json(['message' => 'Only pending requests can be cancelled.'], 403);
        }

        $leave->delete();
        $this->deleteAttachment($leave); // after the record change; the file goes once it is committed

        return response()->json(['message' => 'Leave request cancelled.'], 200);
    }

    // GET /api/my-leave-requests/{id}/attachment (own)
    public function myAttachment(Request $request, $id)
    {
        $employee = $this->myEmployee($request);
        abort_unless($employee, 404);

        return $this->sendAttachment(LeaveRequest::where('employee_id', $employee->id)->findOrFail($id));
    }

    private function sendAttachment(LeaveRequest $leave)
    {
        $disk = Storage::disk($leave->attachment_disk ?: 'public');
        if (! $leave->attachment_path || ! $disk->exists($leave->attachment_path)) {
            return response()->json(['message' => 'This leave request has no attachment.'], 404);
        }

        return $disk->response($leave->attachment_path, basename($leave->attachment_path), [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }

    private function deleteAttachment(LeaveRequest $leave): void
    {
        if ($leave->attachment_path) {
            StoredFiles::deleteAfterCommit($leave->attachment_disk ?: 'public', $leave->attachment_path);
        }
    }
}
