<?php

namespace App\Support\ThesisDemo;

use App\Models\Employee;
use App\Support\Lateness;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

// Completed attendance history. Each row is what the kiosk would have stored
// for that scan (date = the Time In day, late minutes and overtime by the
// kiosk's own rules); nothing is dated the as-of day or later, so live kiosk
// scans never collide with seeded rows. Rows are labeled in `notes` as seeded
// history unless --no-attendance-label.
class AttendanceBuilder
{
    public const LABEL = 'Seeded attendance history (thesis dataset), not a kiosk scan.';

    /** code => Y-m-d => [in, out|null, ot_approved, dateOverride?] or 'absent' */
    private array $overrides = [];

    private array $extra = []; // code => [[date, in, out, approved]]

    public function __construct(private Context $c, private array $leaveDays, private array $dates) {}

    private function scenarios(): void
    {
        $c = $this->c;
        $p = fn (int $n) => $c->paidFromEnd($n);
        $aw = $c->awaiting;
        $o = &$this->overrides;
        $late = function (string $code, string $ymd, int $min) use (&$o, $c) {
            $in = CarbonImmutable::parse("{$ymd} {$c->dayStart}")->addMinutes($min)->addSeconds(Context::pick($code.$ymd, 0, 50))->format('H:i:s');
            $out = CarbonImmutable::parse("{$ymd} {$c->dayEnd}")->addSeconds(Context::pick($code.$ymd.'o', 0, 59))->format('H:i:s');
            $o[$code][$ymd] = [$in, $out, false];
        };

        // E08 late by minutes
        foreach ([[5, 3, 12], [4, 5, 7], [3, 2, 25], [2, 4, 9], [1, 2, 18]] as [$n, $w, $m]) {
            $late('E08', $c->wd($p($n), $w), $m);
        }
        $late('E08', $c->wd($aw, 1), 15);
        $late('E08', $c->wd($aw, 6), 31);

        // E09 late by hours, half day late (D1), late in the awaiting period
        $late('E09', $c->wd($p(3), 4), 150);
        $o['E09'][$c->wd($p(2), 3)] = ['14:30:20', '18:00:40', false];
        $late('E09', $c->wd($aw, 2), 95);

        // E10 absences, missing Time Out (old), early out (no undertime rule)
        $o['E10'][$c->wd($p(3), 2)] = 'absent';
        $o['E10'][$c->wd($p(2), 6)] = 'absent';
        $o['E10'][$c->wd($aw, 4)] = 'absent';
        $o['E10'][$c->wd($p(3), 7)] = ['08:52:10', 'none', false];
        $o['E10'][$c->wd($p(1), 2)] = ['08:51:30', '15:00:10', false];

        // E11 (monthly) one absence
        $o['E11'][$c->wd($p(3), 6)] = 'absent';

        // E06 absent 3 days: his stops were reassigned (deliveries)
        foreach ([[6, 3], [4, 8], [2, 2]] as [$n, $w]) {
            $o['E06'][$c->wd($p($n), $w)] = 'absent';
        }

        // E14 overtime: approved 90 min, not approved 60 min, pending 45 min (awaiting)
        $o['E14'][$c->wd($p(2), 3)] = ['08:50:00', '19:30:30', true];
        $o['E14'][$c->wd($p(1), 5)] = ['08:49:40', '19:00:20', false];
        $o['E14'][$c->wd($aw, 3)] = ['08:51:10', '18:45:50', false];
        // E14 works every holiday in the history window (rest-day ones too)
        foreach ($c->holidays as $ymd => $h) {
            if ($ymd >= $c->ended[0]['start'] && $ymd < $c->asOf->toDateString() && $ymd >= $this->dates['E14']['hired']) {
                $type = $h->type;
                $this->extra['E14'][] = [$ymd, '08:55:00', $type === 'REGULAR' ? '20:00:15' : '18:00:30', true];
            }
        }

        // E02 overnight overtime (approved)
        $o['E02'][$c->wd($p(1), 2)] = ['20:48:00', '07:30:40', true];

        // E22: shift day D has no record; the scan at 00:40 is dated D+1 (D4),
        // and the kiosk then refuses his own D+1 night Time In ("completed").
        $d = $c->wd($p(3), 4);
        $next = $c->date($d)->addDay()->toDateString();
        $o['E22'][$d] = 'absent';
        $o['E22'][$next] = ['00:40:12', '06:00:35', false];
        // E22 Sunday night shift crossing into the next period (dated Sunday)
        $this->extra['E22'][] = [$p(1)['end'], '20:55:00', '06:00:20', false];

        // E18: in the paid period whose pay day is the 1st of its month, only one half day
        $neg = $this->negativePeriod();
        foreach ($c->workDaysOf($neg) as $i => $ymd) {
            $o['E18'][$ymd] = $i === 0 ? ['08:58:00', '12:30:10', false] : 'absent';
        }

        // E12 emergency leave day (left 3 h early) and E13 (left 4 h early)
        $o['E12'][$c->wd($aw, 5)] = ['08:55:00', '15:00:30', false];
        $o['E13'][$c->wd($aw, 7)] = ['08:54:00', '14:00:30', false];

        // E19 missed Time Outs (memo ladder) and the live auto-memo trigger
        foreach ($this->memoDays() as $ymd) {
            $o['E19'][$ymd] = ['08:53:00', 'none', false];
        }
    }

    // The latest paid period whose pay day is the first of its month (P-37).
    public function negativePeriod(): array
    {
        foreach (array_reverse($this->c->paid) as $p) {
            if (\App\Support\PayPeriod::payDayNumber($p['pay_day']) === 1) {
                return $p;
            }
        }

        return $this->c->paidFromEnd(2);
    }

    // E19's missed Time Out days: [memo0 (outside the window), memo1, memo2, memo3, live]
    public function memoDays(): array
    {
        $c = $this->c;
        $live = $c->asOf->subDays(2);
        while (! $c->isWorkDay($live->toDateString()) || $c->isHoliday($live->toDateString())) {
            $live = $live->subDay();
        }
        $old = $c->asOf->subMonths(15);
        while (! $c->isWorkDay($old->toDateString())) {
            $old = $old->addDay();
        }

        return [
            'memo0' => $old->toDateString(),
            'memo1' => $c->wd($c->paidFromEnd(4), 2),
            'memo2' => $c->wd($c->paidFromEnd(2), 3),
            'memo3' => $c->wd($c->awaiting, 2),
            'live' => $live->toDateString(),
        ];
    }

    public function build(): void
    {
        $this->scenarios();
        $c = $this->c;
        $from = $c->ended[0]['start'];
        $until = $c->asOf->subDay()->toDateString();

        foreach (Roster::people() as $code => $person) {
            $empId = $c->emp[$code];
            $employee = Employee::find($empId);
            $d = $this->dates[$code];
            $last = $d['end'] ?? $until;
            $rows = [];

            for ($day = $c->date(max($from, $d['hired'])); $day->toDateString() <= min($last, $until); $day = $day->addDay()) {
                $ymd = $day->toDateString();
                $ov = $this->overrides[$code][$ymd] ?? null;
                if ($ov === null && (! $c->isWorkDay($ymd) || $c->isHoliday($ymd) || isset($this->leaveDays[$code][$ymd]))) {
                    continue;
                }
                if ($ov === 'absent') {
                    continue;
                }
                $rows[] = $ov ? [$ymd, $ov[0], $ov[1], $ov[2]] : $this->normal($code, $employee, $ymd);
            }
            foreach ($this->extra[$code] ?? [] as $x) {
                $rows[] = $x;
            }
            // E19's first missed Time Out lies before the history window
            if ($code === 'E19') {
                $rows[] = [$this->memoDays()['memo0'], '08:53:00', 'none', false];
            }
            // E21 (inactive since long ago): a few weeks of attendance before leaving
            if ($code === 'E21') {
                for ($day = $c->date($d['end'])->subDays(20); $day->toDateString() <= $d['end']; $day = $day->addDay()) {
                    if ($c->isWorkDay($day->toDateString())) {
                        $rows[] = $this->normal($code, $employee, $day->toDateString());
                    }
                }
            }

            foreach ($rows as [$ymd, $in, $out, $approved]) {
                $this->insert("att:{$code}:{$ymd}", $employee, $ymd, $in, $out === 'none' ? null : $out, $approved);
            }
        }
    }

    // An ordinary day: in 3-15 minutes early, out within the minute the shift ends.
    private function normal(string $code, Employee $e, string $ymd): array
    {
        $start = CarbonImmutable::parse("{$ymd} ".$e->shift_start)->subMinutes(Context::pick("{$code}{$ymd}i", 3, 15))->addSeconds(Context::pick("{$code}{$ymd}s", 0, 59));
        $end = CarbonImmutable::parse("{$ymd} ".$e->shift_end)->addSeconds(Context::pick("{$code}{$ymd}o", 0, 59));

        return [$ymd, $start->format('H:i:s'), $end->format('H:i:s'), false];
    }

    // One attendance row as the kiosk would store it.
    public function insert(string $key, Employee $e, string $ymd, string $in, ?string $out, bool $otApproved, string $kind = 'created'): ?int
    {
        $c = $this->c;
        if ($c->m->has($key)) {
            return $c->m->id($key);
        }
        $tz = Context::TZ;
        $inAt = CarbonImmutable::parse("{$ymd} {$in}", $tz);
        $hours = 0.0;
        $ot = 0;
        $outAt = null;
        if ($out !== null) {
            $outAt = CarbonImmutable::parse("{$ymd} {$out}", $tz);
            if ($outAt->lessThanOrEqualTo($inAt)) {
                $outAt = $outAt->addDay();
            }
            $hours = round($inAt->diffInMinutes($outAt, true) / 60, 2);
            $shiftEnd = CarbonImmutable::parse("{$ymd} ".$e->shift_end, $tz);
            if (Lateness::label((string) $e->shift_start, (string) $e->shift_end) === 'night') {
                $shiftEnd = $shiftEnd->addDay();
            }
            $ot = max(0, (int) floor($shiftEnd->diffInSeconds($outAt, false) / 60));
        }
        $late = $c->isHoliday($ymd) ? 0 : Lateness::minutes(Lateness::shiftStart($e), $in);

        return $c->m->insert($key, 'attendances', [
            'face_data_id' => null,
            'employee_id' => $e->id,
            'recorded_by' => null,
            'date' => $ymd,
            'time_in' => $in,
            'time_out' => $outAt?->format('H:i:s'),
            'hours_worked' => $hours,
            'late_minutes' => $late,
            'ot_minutes' => $ot,
            'ot_approved' => $otApproved && $ot > 0,
            'status' => $late > 0 ? 'late' : 'present',
            'notes' => $c->labelAttendance ? self::LABEL : null,
            'created_at' => $inAt->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'),
            'updated_at' => ($outAt ?? $inAt)->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'),
        ]);
    }

    // Existing employees: complete, on-time days in the chosen periods, only
    // where nothing is recorded yet. A period that already has a payroll for
    // the employee is skipped entirely, so no paid or approved payroll changes.
    public function buildExisting(): array
    {
        $c = $this->c;
        $report = [];
        $until = $c->asOf->subDay()->toDateString();
        $periods = array_merge($c->existingPeriods, [$c->current]);

        foreach ($c->existingEmployeeIds as $id) {
            $e = Employee::find($id);
            if (! $e || ! $e->is_active) {
                $report[] = "existing employee #{$id}: skipped (not found or inactive)";

                continue;
            }
            if (! $e->shift_start || ! $e->shift_end) {
                [$s, $en] = $e->shift === 'night' ? [$c->nightStart, $c->nightEnd] : [$c->dayStart, $c->dayEnd];
                $e->shift_start = $s; // in memory only: the profile is not changed
                $e->shift_end = $en;
            }
            $added = 0;
            $skippedPeriods = [];
            foreach ($periods as $p) {
                $hasPayroll = DB::table('payrolls')->where('employee_id', $e->id)
                    ->whereDate('period_start', '<=', $p['end'])->whereDate('period_end', '>=', $p['start'])->exists();
                if ($hasPayroll) {
                    $skippedPeriods[] = "{$p['start']} (payroll exists)";

                    continue;
                }
                $taken = DB::table('attendances')->where(fn ($q) => $q->where('employee_id', $e->id)
                    ->orWhereIn('face_data_id', DB::table('face_data')->where('employee_id', $e->id)->select('id')))
                    ->whereBetween('date', [$p['start'], $p['end']])->pluck('date')
                    ->map(fn ($v) => substr((string) $v, 0, 10))->flip();
                $onLeave = DB::table('leave_requests')->where('employee_id', $e->id)->where('status', 'approved')
                    ->whereDate('start_date', '<=', $p['end'])->whereDate('end_date', '>=', $p['start'])->get(['start_date', 'end_date']);
                foreach ($c->workDaysOf($p) as $ymd) {
                    if ($ymd > $until || $ymd < $e->date_hired->toDateString() || isset($taken[$ymd])) {
                        continue;
                    }
                    if ($onLeave->contains(fn ($l) => substr($l->start_date, 0, 10) <= $ymd && substr($l->end_date, 0, 10) >= $ymd)) {
                        continue;
                    }
                    [$d, $in, $out] = $this->normal("X{$e->id}", $e, $ymd);
                    if ($this->insert("att:existing:{$e->id}:{$ymd}", $e, $ymd, $in, $out, false) !== null) {
                        $added++;
                    }
                }
            }
            $report[] = "existing {$e->employee_id} {$e->first_name} {$e->last_name}: {$added} days".($skippedPeriods ? '; skipped periods '.implode(', ', $skippedPeriods) : '').((float) $e->daily_rate <= 0 && ! $e->monthly_rate ? ' (WARNING: no pay rate on the profile, payroll will be 0)' : '');
        }

        return $report;
    }
}
