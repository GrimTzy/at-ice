<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Payroll;
use App\Support\Audit;
use App\Support\PayPeriod;
use App\Support\PayrollCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// HR: the timesheet of every active employee for one pay period (days present,
// absences, late minutes, leave). Approving a timesheet makes the employee's
// payroll for that period (worked out by App\Support\PayrollCalculator), which
// Admin then pays (PayrollController). HR sees no pay amounts here: salary is
// confidential.
class TimesheetController extends Controller
{
    // GET /api/timesheets?date=Y-m-d (any date in the period; default today)
    public function index(Request $request)
    {
        $request->validate(['date' => 'nullable|date_format:Y-m-d']);
        $period = PayPeriod::for($request->input('date', now(config('atice.timezone'))->toDateString()));
        $start = CarbonImmutable::parse($period['start']);
        $end = CarbonImmutable::parse($period['end']);

        $payrolls = Payroll::whereDate('period_start', $period['start'])->get()->keyBy('employee_id');

        $rows = $this->employees($period)->map(function (Employee $e) use ($start, $end, $payrolls) {
            $sheet = PayrollCalculator::timesheet($e, $start, $end);
            $p = $payrolls->get($e->id);

            return [
                'id' => $e->id,
                'employee_id' => $e->employee_id,
                'name' => $e->full_name,
                'position' => $e->position,
                'employment_status' => $e->employment_status,
                'days_present' => $sheet['days_present'],
                'absent_days' => $sheet['absent_days'],
                'late_minutes' => $sheet['late_minutes'],
                'paid_leave_days' => $sheet['paid_leave_days'],
                'unpaid_leave_days' => $sheet['unpaid_leave_days'],
                'unpaid_leave_hours' => $sheet['unpaid_leave_hours'],
                'missing_time_out' => $sheet['missing_time_out'],
                'holiday_days' => $sheet['holiday_days_present'],
                'ot_pending_minutes' => $sheet['ot_pending_minutes'],
                'ot_approved_hours' => $sheet['ot_hours'],
                // not_approved | unpaid (approved, waiting for pay) | paid
                'status' => $p ? $p->status : 'not_approved',
                'approved_at' => $p?->timesheet_approved_at,
            ];
        })->sortBy('employee_id')->values();

        return response()->json(['period' => $period, 'rows' => $rows]);
    }

    // POST /api/timesheets/approve { date, employee_ids: [] }
    // Approves the timesheets: each makes (or redoes) that employee's payroll for
    // the period. A paid payroll is final and is not touched.
    public function approve(Request $request)
    {
        $data = $request->validate([
            'date' => 'required|date_format:Y-m-d',
            'employee_ids' => 'required|array|min:1',
            'employee_ids.*' => 'integer|exists:employees,id',
            // Overtime is paid only for the employees HR ticks here (R3).
            'approve_ot_ids' => 'nullable|array',
            'approve_ot_ids.*' => 'integer|exists:employees,id',
        ]);
        $approveOt = $data['approve_ot_ids'] ?? [];
        $period = PayPeriod::for($data['date']);
        if ($period['end'] > now(config('atice.timezone'))->toDateString()) {
            throw ValidationException::withMessages(['date' => ['A timesheet can be approved once its pay period has ended ('.$period['end'].').']]);
        }

        // All or nothing, and one approval at a time per employee:
        //  - the whole batch is one transaction, so a failure part way leaves no
        //    payroll and no audit entry behind;
        //  - the employees rows are locked first, in ascending id order. An
        //    employee row always exists, unlike the payroll row that may not
        //    exist yet (a lock on a missing row locks nothing), so two approvals
        //    of the same employee queue here and the second one finds the
        //    payroll the first one made. The same order for every request means
        //    two batches with overlapping employees cannot deadlock;
        //  - the unique key on payrolls (employee_id, period_start) is the last
        //    protection if any other path ever bypasses this lock.
        try {
            [$approved, $skipped, $overlapping] = DB::transaction(function () use ($data, $approveOt, $period, $request) {
                $approved = 0;
                $skipped = [];
                $overlapping = []; // employee code => text of the payroll in the way
                $employees = $this->employees($period, lock: true, ids: $data['employee_ids']);
                // The payrolls that already exist are locked right after, all at once,
                // in ascending payroll id: the same order "mark paid" uses, so the two
                // can never wait for each other (a loop that locked them one employee at
                // a time followed the employee ids, which can run the other way round
                // and deadlocked with mark paid on MySQL). A "mark paid" or an adjustment
                // running now also cannot slip in between the paid check and the write.
                $existing = Payroll::where('period_start', $period['start'])
                    ->whereIn('employee_id', $employees->pluck('id'))
                    ->orderBy('id')->lockForUpdate()->get()->keyBy('employee_id');
                foreach ($employees as $employee) {
                    $key = ['employee_id' => $employee->id, 'period_start' => $period['start']];
                    $payroll = $existing->get($employee->id) ?? new Payroll($key);
                    if ($payroll->exists && $payroll->status === 'paid') {
                        $skipped[] = $employee->employee_id;

                        continue;
                    }
                    // A payroll of the same employee for a different period that shares a day
                    // with this one means the periods were moved after it was made (a changed
                    // cutoff or anchor). Approving would pay those days twice, so this
                    // employee is left out and named in the answer. The same start is the
                    // same period and is simply redone.
                    $clash = Payroll::where('employee_id', $employee->id)
                        ->whereDate('period_start', '!=', $period['start'])
                        ->whereDate('period_start', '<=', $period['end'])
                        ->whereDate('period_end', '>=', $period['start'])
                        ->orderBy('period_start')->first();
                    if ($clash) {
                        $overlapping[$employee->employee_id] = $clash->period_start->toDateString().' to '.$clash->period_end->toDateString();

                        continue;
                    }
                    if (in_array($employee->id, $approveOt, true)) {
                        $ot = Attendance::where('employee_id', $employee->id)->where('ot_minutes', '>', 0)->where('ot_approved', false)
                            ->whereDate('date', '>=', $period['start'])->whereDate('date', '<=', $period['end']);
                        $minutes = (int) $ot->sum('ot_minutes');
                        $ot->update(['ot_approved' => true]);
                        if ($minutes > 0) {
                            Audit::log('overtime.approved', $employee, null, ['minutes' => $minutes, 'period' => $period['start'].' to '.$period['end']]);
                        }
                    }
                    $adjustments = $payroll->exists ? $payroll->adjustments()->get(['amount', 'taxable'])->toArray() : [];
                    $payroll->fill(PayrollCalculator::compute($employee, $period, $adjustments) + [
                        'period_end' => $period['end'],
                        'status' => 'unpaid',
                        'prepared_by' => $request->user()->id,
                        'timesheet_approved_by' => $request->user()->id,
                        'timesheet_approved_at' => now(),
                    ])->save();
                    Audit::log('timesheet.approved', $payroll, null, ['employee_id' => $employee->employee_id, 'period' => $period['start'].' to '.$period['end']]);
                    $approved++;
                }

                return [$approved, $skipped, $overlapping];
            });
        } catch (UniqueConstraintViolationException $e) {
            // Only the payroll key conflict is a "someone else saved it first". Any other
            // unique violation is a different problem and must not be hidden behind this
            // message. The transaction is already rolled back: nothing half-written.
            // MySQL names the index in its message; SQLite names the two columns instead.
            if (! str_contains($e->getMessage(), 'payrolls_employee_period_unique') && ! str_contains($e->getMessage(), 'payrolls.employee_id, payrolls.period_start')) {
                throw $e;
            }

            return response()->json(['message' => 'Another approval of this pay period is being saved. Please try again in a moment.'], 409);
        }

        $blocked = collect($overlapping)->map(fn ($text, $code) => "{$code} (already has {$text}; this period is {$period['start']} to {$period['end']})")->implode(', ');

        return response()->json([
            'message' => "{$approved} timesheet".($approved === 1 ? '' : 's').' approved.'
                .($skipped ? ' Already paid, not changed: '.implode(', ', $skipped).'.' : '')
                .($overlapping ? " Not approved, this period overlaps a payroll that was already made: {$blocked}. The pay periods were changed after that payroll; ask the developer." : ''),
            'approved' => $approved,
            'skipped' => $skipped,
            'overlapping' => array_keys($overlapping),
        ]);
    }

    // Employees on the payroll in a period: active, hired by its end.
    // `lock`: FOR UPDATE on exactly the employees asked for (`ids`), in ascending
    // id order (the order every writer must use), so unrelated employees stay free.
    private function employees(array $period, bool $lock = false, ?array $ids = null)
    {
        return Employee::where('is_active', true)
            ->whereDate('date_hired', '<=', $period['end'])
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))
            ->orderBy('id')
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->get();
    }
}
