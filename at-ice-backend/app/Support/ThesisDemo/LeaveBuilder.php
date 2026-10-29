<?php

namespace App\Support\ThesisDemo;

use App\Models\Employee;
use App\Support\LeaveCredits;
use App\Support\Settings;
use Carbon\CarbonPeriod;

// Leave requests in every supported state. Approved ones are paid from the
// credits the app itself computes on the start date (LeaveCredits), inserted
// in date order, the same as HR approving them one by one. Rejected inputs
// (probationary, late SL, short VL notice, overlap) are not inserted: they
// are shown live or by the tests.
class LeaveBuilder
{
    public function __construct(private Context $c, private SampleFiles $files) {}

    // [key, code, type, start, end, half, hours, status, filedDaysBefore, reason, opts]
    public function plan(): array
    {
        $c = $this->c;
        $p = fn (int $n) => $c->paidFromEnd($n);
        $aw = $c->awaiting;
        $wd = fn (array $period, int $n) => $c->wd($period, $n);
        $fri = $wd($p(1), 5);
        $mon = $c->date($fri)->addDays(3)->toDateString();
        $future = function (int $daysAhead) use ($c) {
            $d = $c->asOf->addDays($daysAhead);
            while (! $c->isWorkDay($d->toDateString()) || $c->isHoliday($d->toDateString())) {
                $d = $d->addDay();
            }

            return $d->toDateString();
        };
        $f21 = $future(21);
        $f35 = $future(35);
        $asOf = $c->asOf->toDateString();

        return [
            ['L-04', 'E12', 'sick', $wd($p(6), 4), $wd($p(6), 4), false, null, 'approved', -1, 'Fever and colds, advised rest by the barangay health center.', ['unpaid' => true, 'note' => 'Marked unpaid by HR: medical certificate did not cover the date.']],
            ['L-03', 'E12', 'sick', $wd($p(3), 6), $wd($p(3), 6), false, null, 'approved', -1, 'Acute gastroenteritis.', ['attach' => true]],
            ['L-02', 'E12', 'vacation', $wd($p(2), 7), $wd($p(2), 8), false, null, 'approved', 20, 'Family event in Pampanga.', []],
            ['L-16', 'E12', 'vacation', $fri, $mon, false, null, 'approved', 18, 'Long weekend trip (filed Friday to Monday).', []],
            ['L-15', 'E12', 'vacation', $wd($aw, 3), $wd($aw, 3), true, null, 'approved', 15, 'Half day: enrollment of my child.', []],
            ['L-06', 'E12', 'emergency', $wd($aw, 5), $wd($aw, 5), false, 3, 'approved', 0, 'Left 3 hours early: child brought to the clinic.', []],
            ['L-01', 'E12', 'vacation', $f21, $c->date($f21)->addDay()->toDateString(), false, null, 'pending', 1, 'Vacation leave for a family reunion.', ['filed_at' => $c->asOf->subDay()->toDateString()]],
            ['L-08', 'E12', 'vacation', $f35, $f35, false, null, 'pending', 1, 'Personal errand (to be cancelled in the demo).', ['filed_at' => $c->asOf->subDay()->toDateString()]],
            ['L-05', 'E13', 'vacation', $wd($aw, 2), $wd($aw, 4), false, null, 'approved', 15, 'Out-of-town trip to Nueva Ecija.', []],
            ['L-07', 'E13', 'vacation', $wd($aw, 8), $wd($aw, 9), false, null, 'rejected', 15, 'Personal reasons.', ['reject' => 'Peak delivery week with two salesmen already on leave. Please refile for another week.']],
            ['L-06b', 'E13', 'emergency', $wd($aw, 7), $wd($aw, 7), false, 4, 'approved', 0, 'Left 4 hours early: flooding at home.', []],
            ['L-18', 'E06', 'vacation', $asOf, $c->asOf->addDay()->toDateString(), false, null, 'approved', 20, 'Vacation leave (barangay fiesta in the province).', []],
        ];
    }

    // Dates (Y-m-d => code) covered by approved non-emergency leave: the
    // attendance builder leaves these days empty.
    public function leaveDays(): array
    {
        $out = [];
        foreach ($this->plan() as $l) {
            if ($l[7] === 'approved' && $l[2] !== 'emergency') {
                foreach (CarbonPeriod::create($l[3], $l[4]) as $d) {
                    $out[$l[1]][$d->toDateString()] = true;
                }
            }
        }

        return $out;
    }

    public function build(): void
    {
        $c = $this->c;
        $plan = $this->plan();
        usort($plan, fn ($a, $b) => strcmp($a[3], $b[3]));
        $hpd = (float) Settings::get('payroll.hours_per_day');

        foreach ($plan as [$key, $code, $type, $start, $end, $half, $hours, $status, $before, $reason, $opt]) {
            $mkey = "leave:{$key}";
            if ($c->m->has($mkey)) {
                continue;
            }
            $employee = Employee::find($c->emp[$code]);

            // filed: SL the day after returning, VL some days ahead, EL that day
            $filed = $opt['filed_at'] ?? ($before < 0 ? $c->date($end)->addDay()->toDateString() : $c->date($start)->subDays($before)->toDateString());
            $days = $type === 'emergency' ? $hours / $hpd : ($half ? 0.5 : $this->countDays($start, $end));

            $row = [
                'employee_id' => $employee->id,
                'leave_type' => $type,
                'start_date' => $start,
                'end_date' => $end,
                'total_days' => round($days, 2),
                'hours' => $type === 'emergency' ? $hours : null,
                'is_half_day' => $half,
                'reason' => $reason,
                'status' => $status,
                'is_paid' => null,
                'paid_days' => 0,
                'unpaid_days' => 0,
                'attachment_disk' => 'local',
                'created_at' => $c->stamp($filed, $type === 'emergency' ? '11:30:00' : '08:15:00'),
                'updated_at' => $c->stamp($filed, '08:15:00'),
            ];
            if (! empty($opt['attach'])) {
                $row['attachment_path'] = $this->files->pdf("medcert:{$key}", 'local', 'leave-attachments', 'Medical Certificate (sample)', [
                    'Patient: '.$employee->first_name.' '.$employee->last_name,
                    'Date of consultation: '.$start,
                    'Diagnosis: '.$reason,
                    'Recommendation: rest for one (1) day.',
                    'Clinic: (fictional) San Jose Family Clinic',
                    'This is not a real medical certificate.',
                ]);
            }
            if ($status !== 'pending') {
                $decided = $c->stamp($c->date($filed)->addDay()->toDateString(), '10:00:00');
                $row += ['approved_by' => $c->hrUserId, 'approved_at' => $decided];
                $row['updated_at'] = $decided;
            }
            if ($status === 'rejected') {
                $row['reject_reason'] = $opt['reject'];
            }
            if ($status === 'approved') {
                // the app's rule at approval: paid from the credits on the start date
                $available = LeaveCredits::for($employee, $start)['available'];
                $paid = ! empty($opt['unpaid']) ? 0 : min($days, $available);
                $row['paid_days'] = round($paid, 2);
                $row['unpaid_days'] = round($days - $paid, 2);
                $row['is_paid'] = $paid >= $days;
                $row['admin_notes'] = $opt['note'] ?? null;
            }
            $id = $c->m->insert($mkey, 'leave_requests', $row);
            $c->note("leave {$key} {$code} {$type} {$start}..{$end} {$status} paid={$row['paid_days']} unpaid={$row['unpaid_days']} (id {$id})");
        }
    }

    // Same rule as LeaveRequestController::computeDays: every day of the
    // range except holidays (weekends included; reported as defect D3).
    private function countDays(string $start, string $end): float
    {
        $n = 0;
        foreach (CarbonPeriod::create($start, $end) as $d) {
            if (! $this->c->isHoliday($d->toDateString())) {
                $n++;
            }
        }

        return (float) $n;
    }
}
