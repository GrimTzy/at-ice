<?php

namespace App\Support\ThesisDemo;

use App\Models\Employee;
use App\Support\PayrollCalculator;
use Illuminate\Support\Facades\DB;

// Paid payroll history for the fictional employees: the app's own
// PayrollCalculator, period by period in date order (so a carried-over
// shortfall reaches the next payroll), saved as HR approved and paid on the
// pay day. Paid means "recorded as paid" only; no transfer exists.
// The awaiting period and the current one get no payroll: HR approves live.
class PayrollBuilder
{
    public function __construct(private Context $c, private array $dates) {}

    // Adjustments per employee and period: [label, amount, taxable]
    public function adjustments(string $code, array $period): array
    {
        $out = [];
        if ($code === 'E01') {
            $out[] = ['Rice allowance', 500.00, false];
        }
        if ($code === 'E11' && $period['start'] === $this->c->paidFromEnd(1)['start']) {
            $out[] = ['Performance incentive', 2000.00, true];
        }

        return $out;
    }

    public function eligible(string $code, array $period): bool
    {
        $d = $this->dates[$code];

        return ! in_array($code, ['E20', 'E21'], true)
            && $d['hired'] <= $period['end']
            && ($d['end'] === null || $d['end'] >= $period['start']);
    }

    public function build(): void
    {
        $c = $this->c;
        foreach ($c->paid as $period) {
            foreach (array_keys(Roster::people()) as $code) {
                if (! $this->eligible($code, $period)) {
                    continue;
                }
                $key = "payroll:{$code}:{$period['start']}";
                if ($c->m->has($key)) {
                    continue;
                }
                $employee = Employee::find($c->emp[$code]);
                $adj = array_map(fn ($a) => ['amount' => $a[1], 'taxable' => $a[2]], $this->adjustments($code, $period));
                $values = PayrollCalculator::compute($employee, $period, $adj);
                $approvedAt = $c->stamp($c->date($period['end'])->addDay()->toDateString(), '10:00:00');
                $paidAt = $c->stamp($period['pay_day'], '16:00:00');
                $id = $c->m->insert($key, 'payrolls', $values + [
                    'employee_id' => $employee->id,
                    'period_start' => $period['start'],
                    'period_end' => $period['end'],
                    'status' => 'paid',
                    'prepared_by' => $c->hrUserId,
                    'timesheet_approved_by' => $c->hrUserId,
                    'timesheet_approved_at' => $approvedAt,
                    'paid_at' => $paidAt,
                    'paid_by' => $c->hrUserId,
                    'created_at' => $approvedAt,
                    'updated_at' => $paidAt,
                ]);
                foreach ($this->adjustments($code, $period) as $i => [$label, $amount, $taxable]) {
                    $c->m->insert("payadj:{$code}:{$period['start']}:{$i}", 'payroll_adjustments', [
                        'payroll_id' => $id, 'label' => $label, 'amount' => $amount, 'taxable' => $taxable,
                        'created_by' => $c->hrUserId, 'created_at' => $approvedAt, 'updated_at' => $approvedAt,
                    ]);
                }
            }
        }
        $this->buildExisting();
    }

    // Existing employees (--existing): paid history for the paid periods in
    // which the dataset seeded their attendance, so a closed period does not
    // show them unpaid next to paid fictional staff. Only periods that end on
    // or after their hire date (the ones the Timesheets page lists). A period
    // that already has a payroll (made by HR) is never touched. The awaiting
    // period stays for HR to approve live.
    public function buildExisting(): void
    {
        $c = $this->c;
        foreach ($c->existingEmployeeIds as $id) {
            $employee = Employee::find($id);
            if (! $employee || ! $employee->is_active) {
                continue;
            }
            foreach ($c->paid as $period) {
                $key = "payroll:existing:{$id}:{$period['start']}";
                if ($c->m->has($key) || $employee->date_hired->toDateString() > $period['end']) {
                    continue;
                }
                if (DB::table('payrolls')->where('employee_id', $id)->where('period_start', $period['start'])->exists()) {
                    $c->note("existing {$employee->employee_id} {$period['start']}: a payroll already exists, not changed");

                    continue;
                }
                if (! self::hasSeededAttendance($c, $id, $period)) {
                    continue;
                }
                $values = PayrollCalculator::compute($employee, $period, []);
                $approvedAt = $c->stamp($c->date($period['end'])->addDay()->toDateString(), '10:00:00');
                $paidAt = $c->stamp($period['pay_day'], '16:00:00');
                $c->m->insert($key, 'payrolls', $values + [
                    'employee_id' => $id,
                    'period_start' => $period['start'],
                    'period_end' => $period['end'],
                    'status' => 'paid',
                    'prepared_by' => $c->hrUserId,
                    'timesheet_approved_by' => $c->hrUserId,
                    'timesheet_approved_at' => $approvedAt,
                    'paid_at' => $paidAt,
                    'paid_by' => $c->hrUserId,
                    'notes' => 'Seeded payroll history (thesis dataset).',
                    'created_at' => $approvedAt,
                    'updated_at' => $paidAt,
                ]);
                $c->note("existing {$employee->employee_id} {$period['start']}: paid payroll seeded, net {$values['net_pay']}");
            }
        }
    }

    // True when the dataset seeded at least one attendance day for this
    // employee inside the period.
    public static function hasSeededAttendance(Context $c, int $employeeId, array $period): bool
    {
        return DB::table('attendances as a')
            ->join(Manifest::TABLE.' as m', fn ($j) => $j->on('m.record_id', '=', 'a.id')
                ->where('m.table_name', 'attendances')->where('m.dataset', $c->m->dataset))
            ->where('a.employee_id', $employeeId)
            ->whereBetween('a.date', [$period['start'], $period['end']])
            ->exists();
    }
}
