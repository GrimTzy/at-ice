<?php

namespace App\Support\ThesisDemo;

use App\Models\Employee;
use App\Support\PayrollCalculator;

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
    }
}
