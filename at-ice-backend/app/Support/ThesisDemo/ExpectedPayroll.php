<?php

namespace App\Support\ThesisDemo;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

// Expected payroll worked out separately from the app: it reads the raw rows
// (attendance, approved leave, holidays, contribution_rates, pay_multipliers,
// settings) and applies the written rules step by step. It does not call
// PayrollCalculator, Rates, Money, PayPeriod or LeaveCredits, so comparing
// its result with the stored payroll is a real check, not the calculator
// agreeing with itself. Each result carries the formula steps.
class ExpectedPayroll
{
    private array $set;

    private array $holidays;

    private array $rates = [];

    private array $mult = [];

    public function __construct()
    {
        $stored = DB::table('settings')->pluck('value', 'key')->map(fn ($v) => json_decode($v, true))->all();
        $get = fn ($k, $d) => $stored[$k] ?? $d;
        $this->set = [
            'anchor' => (string) ($stored['payroll.anchor'] ?? config('services.payroll.anchor', '2026-01-05')),
            'days' => (int) $get('payroll.cutoff_days', 14),
            'offset' => (int) $get('payroll.payday_offset_days', 5),
            'hpd' => (float) $get('payroll.hours_per_day', 8),
            'divisor' => (float) $get('payroll.monthly_to_daily_divisor', 21.83),
            'monthly_unworked' => (bool) $get('payroll.monthly_unworked_holiday_deduct', false),
            'negative' => (string) $get('payroll.negative_net_handling', 'CARRY_OVER'),
            'gov' => (string) $get('payroll.gov_deduction_strategy', 'SECOND_PAYROLL_OF_MONTH'),
            'break' => (float) $get('attendance.break_hours', 1),
            'night_share' => max(0.10, (float) $get('payroll.night_diff_rate', 0.10)),
            'work_days' => array_map('intval', explode(',', (string) $get('attendance.work_days', '1,2,3,4,5'))),
        ];
        foreach (DB::table('holidays')->get() as $h) {
            $this->holidays[substr($h->date, 0, 10)] = $h;
        }
        foreach (DB::table('contribution_rates')->orderBy('effective_from')->get() as $r) {
            $this->rates[$r->type][] = [substr($r->effective_from, 0, 10), json_decode($r->rules, true)];
        }
        foreach (DB::table('pay_multipliers')->orderBy('effective_date')->get() as $m) {
            $this->mult[$m->key][] = [substr($m->effective_date, 0, 10), (float) $m->value];
        }
    }

    public static function r2(float $v): float
    {
        return round($v + ($v >= 0 ? 1e-9 : -1e-9), 2, PHP_ROUND_HALF_UP);
    }

    private function rule(string $type, string $date): ?array
    {
        $out = null;
        foreach ($this->rates[$type] ?? [] as [$from, $rules]) {
            if ($from <= $date) {
                $out = $rules;
            }
        }

        return $out;
    }

    private function mult(string $key, string $date): float
    {
        $out = 1.0;
        foreach ($this->mult[$key] ?? [] as [$from, $v]) {
            if ($from <= $date) {
                $out = $v;
            }
        }

        return $out;
    }

    // Night minutes of one record, counted minute by minute from the clock
    // (10:00 PM to 6:00 AM), split into the regular part and the overtime part.
    private function nightSplit(object $r, string $ymd, float $hpd): array
    {
        $toMin = fn ($t) => (int) substr($t, 0, 2) * 60 + (int) substr($t, 3, 2);
        $in = $toMin($r->time_in);
        $out = $toMin($r->time_out);
        if ($out <= $in) {
            $out += 1440;
        }
        $regEnd = max($in, $out - (int) $r->ot_minutes);
        $isNight = fn ($m) => ($m % 1440) >= 1320 || ($m % 1440) < 360;
        $reg = 0;
        for ($m = $in; $m < $regEnd; $m++) {
            $reg += $isNight($m) ? 1 : 0;
        }
        $ot = 0;
        if ($r->ot_approved) {
            for ($m = $regEnd; $m < $out; $m++) {
                $ot += $isNight($m) ? 1 : 0;
            }
        }
        $span = ($regEnd - $in) / 60;
        $paid = $span > $hpd ? min($hpd, max(0, $span - $this->set['break'])) : $span;

        return [min($reg / 60, $paid), $ot / 60];
    }

    private function d(string $ymd): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', substr($ymd, 0, 10), 'UTC');
    }

    // DOLE: an unworked regular holiday is paid only when the employee was at
    // work or on paid leave on the work day before it (rest days and other
    // holidays skipped). Worked out here on its own, from the tables.
    private function atWorkDayBefore($employee, string $holiday, array $workDays, string $hired): bool
    {
        $day = $this->d($holiday)->subDay();
        for ($i = 0; $i < 14 && (! in_array($day->dayOfWeekIso, $workDays, true) || isset($this->holidays[$day->toDateString()])); $i++) {
            $day = $day->subDay();
        }
        $ymd = $day->toDateString();
        if ($ymd < $hired) {
            return false;
        }
        $faceIds = DB::table('face_data')->where('employee_id', $employee->id)->pluck('id');
        $worked = DB::table('attendances')->where('date', 'like', $ymd.'%')->whereNotNull('time_in')
            ->where(fn ($q) => $q->where('employee_id', $employee->id)->orWhereIn('face_data_id', $faceIds))
            ->exists();

        return $worked || DB::table('leave_requests')->where('employee_id', $employee->id)->where('status', 'approved')
            ->where('start_date', '<=', $ymd)->where('end_date', '>=', $ymd)->where('paid_days', '>', 0)
            ->exists();
    }

    // n-th pay day of its month (1, 2 or 3), from the anchor rule.
    public function payDayNumber(string $payDay): int
    {
        $pd = $this->d($payDay);
        $anchor = $this->d($this->set['anchor']);
        $list = [];
        for ($k = -60; $k <= 120; $k++) {
            $p = $anchor->addDays($k * $this->set['days'] + $this->set['days'] - 1 + $this->set['offset']);
            if ($p->year === $pd->year && $p->month === $pd->month) {
                $list[] = $p->toDateString();
            }
        }
        sort($list);
        $i = array_search($pd->toDateString(), $list, true);

        return $i === false ? 0 : $i + 1;
    }

    public function govShare(string $payDay): float
    {
        $n = $this->payDayNumber($payDay);

        return match ($this->set['gov']) {
            'FIRST_PAYROLL_OF_MONTH' => $n === 1 ? 1.0 : 0.0,
            'SPLIT_FIRST_TWO' => in_array($n, [1, 2], true) ? 0.5 : 0.0,
            default => $n === 2 ? 1.0 : 0.0, // SECOND_PAYROLL_OF_MONTH
        };
    }

    // $employee: raw employees row. $adjustments: [[label, amount, taxable]]. $carryIn: previous expected carry_over_out.
    public function compute(object $employee, array $period, array $adjustments, float $carryIn, ?string $today = null): array
    {
        $s = $this->set;
        $today ??= CarbonImmutable::now('Asia/Manila')->toDateString();
        $hpd = $s['hpd'];
        $steps = [];
        $monthly = $employee->pay_basis === 'monthly' && $employee->monthly_rate ? (float) $employee->monthly_rate : null;
        $daily = $monthly !== null ? self::r2($monthly / $s['divisor']) : self::r2((float) $employee->daily_rate);
        $hourly = self::r2($daily / $hpd);
        $perMin = $hourly / 60;
        $steps[] = $monthly !== null ? "daily = {$monthly}/{$s['divisor']} = {$daily}; hourly = {$daily}/{$hpd} = {$hourly}" : "daily {$daily}; hourly = {$daily}/{$hpd} = {$hourly}";

        $faceIds = DB::table('face_data')->where('employee_id', $employee->id)->pluck('id')->all();
        $att = DB::table('attendances')->whereNotNull('time_in')
            ->where('date', '>=', $period['start'])->where('date', '<=', $period['end'])
            ->where(fn ($q) => $q->where('employee_id', $employee->id)->when($faceIds, fn ($w) => $w->orWhereIn('face_data_id', $faceIds)))
            ->get();
        $byDay = [];
        foreach ($att as $a) {
            $byDay[substr($a->date, 0, 10)][] = $a;
        }

        $present = 0.0;
        $late = 0;
        $holidayWorked = [];
        $otOrdinary = 0.0;
        $missing = 0;
        foreach ($byDay as $ymd => $rows) {
            $hours = array_sum(array_map(fn ($r) => (float) $r->hours_worked, $rows));
            $hasOut = (bool) array_filter($rows, fn ($r) => $r->time_out !== null);
            $missing += $hasOut ? 0 : 1;
            $present += ($hasOut && $hours > 0 && $hours < 4) ? 0.5 : 1;
            $otAp = array_sum(array_map(fn ($r) => $r->ot_approved ? (int) $r->ot_minutes : 0, $rows)) / 60;
            if (isset($this->holidays[$ymd])) {
                $paidH = $hours > $hpd ? min($hpd, max(0, $hours - $s['break'])) : $hours;
                $rest = ! in_array($this->d($ymd)->dayOfWeekIso, $s['work_days'], true);
                $holidayWorked[] = [$ymd, $this->holidays[$ymd], $rest, $paidH, $otAp];
            } else {
                $late += array_sum(array_map(fn ($r) => (int) $r->late_minutes, $rows));
                $otOrdinary += $otAp;
            }
        }
        $hired = substr((string) $employee->date_hired, 0, 10);
        $holidayUnworked = [];
        foreach ($this->holidays as $ymd => $h) {
            if ($ymd >= $period['start'] && $ymd <= $period['end'] && ! isset($byDay[$ymd]) && $ymd >= $hired && $ymd <= $today) {
                $holidayUnworked[] = [$ymd, $h, ! in_array($this->d($ymd)->dayOfWeekIso, $s['work_days'], true), $this->atWorkDayBefore($employee, $ymd, $s['work_days'], $hired)];
            }
        }

        $leaveDay = [];
        $paidLeave = 0.0;
        $unpaidLeave = 0.0;
        $unpaidHours = 0.0;
        $leaves = DB::table('leave_requests')->where('employee_id', $employee->id)->where('status', 'approved')
            ->where('start_date', '<=', $period['end'])->where('end_date', '>=', $period['start'])->get();
        foreach ($leaves as $l) {
            $total = max(0.0001, (float) $l->total_days);
            $share = min(1, (float) $l->paid_days / $total);
            if ($l->leave_type === 'emergency' && $l->hours !== null) {
                $unpaidHours += (float) $l->unpaid_days * $hpd;
                $leaveDay[substr($l->start_date, 0, 10)] = true;

                continue;
            }
            $per = $l->is_half_day ? 0.5 : 1;
            for ($d = $this->d(max(substr($l->start_date, 0, 10), $period['start'])); $d->toDateString() <= min(substr($l->end_date, 0, 10), $period['end']); $d = $d->addDay()) {
                $ymd = $d->toDateString();
                if (isset($this->holidays[$ymd]) || ! in_array($d->dayOfWeekIso, $s['work_days'], true)) {
                    continue;
                }
                $leaveDay[$ymd] = true;
                $paidLeave += $per * $share;
                $unpaidLeave += $per * (1 - $share);
            }
        }

        $absent = 0;
        $workSinceHire = 0;
        $scheduled = 0;
        for ($d = $this->d($period['start']); $d->toDateString() <= $period['end']; $d = $d->addDay()) {
            $ymd = $d->toDateString();
            if (! in_array($d->dayOfWeekIso, $s['work_days'], true)) {
                continue;
            }
            if (! isset($this->holidays[$ymd])) {
                $scheduled++;
            }
            if ($ymd < $hired) {
                continue;
            }
            $workSinceHire++;
            if ($ymd <= $today && ! isset($byDay[$ymd]) && ! isset($leaveDay[$ymd]) && ! isset($this->holidays[$ymd])) {
                $absent++;
            }
        }
        $holidayDaysPresent = count($holidayWorked);
        $steps[] = "present {$present} (holiday days {$holidayDaysPresent}), absent {$absent}, late {$late} min, paid leave ".round($paidLeave, 2).', unpaid leave '.round($unpaidLeave, 2).", unpaid EL hours {$unpaidHours}, missing Time Out {$missing}";

        // Pay
        if ($monthly !== null) {
            $hiredMid = $hired > $period['start'];
            $basic = $hiredMid ? $daily * $workSinceHire : $monthly * 12 / 26;
            $steps[] = $hiredMid ? "basic = daily {$daily} x {$workSinceHire} work days since hire" : "basic = {$monthly} x 12 / 26";
            $leavePay = 0.0;
            $absence = $absent * $daily;
            $leaveDed = round($unpaidLeave, 2) * $daily + round($unpaidHours, 2) * $hourly;
        } else {
            $basic = max(0, $present - $holidayDaysPresent) * $daily;
            $steps[] = 'basic = ('.$present.' - '.$holidayDaysPresent.") x {$daily}";
            $leavePay = round($paidLeave, 2) * $daily;
            $absence = 0.0;
            $leaveDed = round($unpaidHours, 2) * $hourly;
        }
        $lateDed = $late * $perMin;
        if ($late) {
            $steps[] = "late = {$late} x ({$hourly}/60)";
        }

        $holidayPay = 0.0;
        $holidayOt = 0.0;
        foreach ($holidayWorked as [$ymd, $h, $rest, $hrs, $ot]) {
            $key = $h->is_double ? 'DOUBLE_HOLIDAY' : ($h->type === 'REGULAR' ? ($rest ? 'REGULAR_HOLIDAY_REST_DAY' : 'REGULAR_HOLIDAY_WORKED') : ($h->type === 'SPECIAL_WORKING' ? 'SPECIAL_WORKING_DAY_WORKED' : 'SPECIAL_HOLIDAY_WORKED'));
            $rate = $this->mult($key, $ymd);
            $holidayPay += $hrs * $hourly * ($monthly !== null ? max(0, $rate - 1) : $rate);
            $otKey = ($h->type === 'REGULAR' || $h->is_double) ? 'OT_REGULAR_HOLIDAY' : 'OT_SPECIAL_HOLIDAY';
            $holidayOt += $ot * $hourly * $this->mult($otKey, $ymd);
            $steps[] = "holiday {$ymd} {$key}: {$hrs} h x {$hourly} x ".($monthly !== null ? 'premium '.max(0, $rate - 1) : $rate).($ot ? "; OT {$ot} h x {$this->mult($otKey, $ymd)}" : '');
        }
        foreach ($holidayUnworked as [$ymd, $h, $rest, $atWork]) {
            if ($rest || $h->type === 'SPECIAL_WORKING') {
                continue;
            }
            // not at work (or on paid leave) the work day before: an unpaid day
            $rate = ($h->type === 'REGULAR' && ! $atWork) ? 0.0
                : $this->mult($h->type === 'REGULAR' ? 'REGULAR_HOLIDAY_UNWORKED' : 'SPECIAL_HOLIDAY_UNWORKED', $ymd);
            if ($monthly !== null) {
                if ($s['monthly_unworked']) {
                    $absence += $daily * max(0, 1 - $rate);
                }
            } else {
                $holidayPay += $hpd * $hourly * $rate;
            }
            $steps[] = "unworked holiday {$ymd}: rate {$rate}";
        }
        $otRate = $this->mult('OT_ORDINARY', $period['end']);
        $otPay = $otOrdinary * $hourly * $otRate;
        if ($otOrdinary) {
            $steps[] = "ordinary OT {$otOrdinary} h x {$hourly} x {$otRate}";
        }

        // Night differential: share x the rate of each night hour
        $nightPay = 0.0;
        $nightHours = 0.0;
        foreach ($byDay as $ymd => $rows) {
            $reg = 0.0;
            $ot = 0.0;
            foreach ($rows as $r) {
                if ($r->time_in && $r->time_out) {
                    [$a, $b] = $this->nightSplit($r, $ymd, $hpd);
                    $reg += $a;
                    $ot += $b;
                }
            }
            if ($reg <= 0 && $ot <= 0) {
                continue;
            }
            $h = $this->holidays[$ymd] ?? null;
            $rest = ! in_array($this->d($ymd)->dayOfWeekIso, $s['work_days'], true);
            $dayRate = $h ? $this->mult($h->is_double ? 'DOUBLE_HOLIDAY' : ($h->type === 'REGULAR' ? ($rest ? 'REGULAR_HOLIDAY_REST_DAY' : 'REGULAR_HOLIDAY_WORKED') : ($h->type === 'SPECIAL_WORKING' ? 'SPECIAL_WORKING_DAY_WORKED' : 'SPECIAL_HOLIDAY_WORKED')), $ymd) : 1.0;
            $nOtRate = $this->mult($h ? (($h->type === 'REGULAR' || $h->is_double) ? 'OT_REGULAR_HOLIDAY' : 'OT_SPECIAL_HOLIDAY') : 'OT_ORDINARY', $ymd);
            $nightPay += ($reg * $dayRate + $ot * $nOtRate) * $hourly * $s['night_share'];
            $nightHours += $reg + $ot;
            $steps[] = "night {$ymd}: ".round($reg, 2)." h x {$dayRate}".($ot ? ' + OT '.round($ot, 2)." h x {$nOtRate}" : '')." x {$hourly} x {$s['night_share']}";
        }

        $adjTotal = 0.0;
        $adjNon = 0.0;
        foreach ($adjustments as [$label, $amount, $taxable]) {
            $adjTotal += $amount;
            $adjNon += $taxable ? 0 : $amount;
            $steps[] = "{$label} +{$amount}".($taxable ? ' (taxable)' : ' (non-taxable)');
        }
        $gross = max(0, $basic + $leavePay + $holidayPay + $holidayOt + $otPay + $nightPay + $adjTotal - $lateDed - $absence - $leaveDed);

        $basis = $monthly ?? $daily * $s['divisor'];
        $share = $this->govShare($period['pay_day']);
        $sssR = $this->rule('sss', $period['pay_day']);
        $phR = $this->rule('philhealth', $period['pay_day']);
        $hdR = $this->rule('pagibig', $period['pay_day']);
        $msc = $sssR ? min($sssR['msc_max'], max($sssR['msc_min'], round($basis / $sssR['msc_step']) * $sssR['msc_step'])) : 0;
        $sss = $sssR ? $sssR['employee_rate'] * $msc * $share : 0.0;
        $ph = $phR ? $phR['employee_rate'] * min($phR['basis_max'], max($phR['basis_min'], $basis)) * $share : 0.0;
        $hd = $hdR ? min($hdR['max'], $hdR['employee_rate'] * $basis) * $share : 0.0;
        $steps[] = 'pay day #'.$this->payDayNumber($period['pay_day'])." -> gov share {$share}; monthly basis ".round($basis, 2)."; SSS MSC {$msc}";
        $taxable = max(0, $gross - $adjNon - $sss - $ph - $hd);
        $tax = 0.0;
        $taxR = $this->rule('tax', $period['pay_day']);
        if ($taxR && $taxable > 0) {
            $annual = $taxable * 26;
            $br = null;
            foreach ($taxR['annual_brackets'] as $b) {
                if ($annual > $b['over']) {
                    $br = $b;
                }
            }
            $tax = $br ? ($br['base'] + $br['rate'] * ($annual - $br['over'])) / 26 : 0.0;
            $steps[] = 'tax: annual '.round($annual, 2).' -> '.round($tax, 2).' per period';
        }
        $net = $gross - $sss - $ph - $hd - $tax - $carryIn;
        $short = $net < 0 ? -$net : 0.0;
        $net = max(0.0, $net);
        if ($carryIn) {
            $steps[] = "carry-in {$carryIn} taken";
        }
        if ($short) {
            $steps[] = 'shortfall '.self::r2($short).' -> '.($s['negative'] === 'CARRY_OVER' ? 'carried to next cut-off' : 'employer shouldered');
        }

        return [
            'scheduled_days' => $scheduled,
            'work_days_since_hire' => $workSinceHire,
            'days_present' => round($present, 2),
            'absent_days' => (float) $absent,
            'late_minutes' => $late,
            'missing_time_out' => $missing,
            'paid_leave_days' => round($paidLeave, 2),
            'unpaid_leave_days' => round($unpaidLeave, 2),
            'unpaid_leave_hours' => round($unpaidHours, 2),
            'holiday_hours' => round(array_sum(array_column($holidayWorked, 3)), 2),
            'ot_hours' => round($otOrdinary + array_sum(array_column($holidayWorked, 4)), 2),
            'night_hours' => round($nightHours, 2),
            'daily_rate' => $daily,
            'hourly_rate' => $hourly,
            'gov_share' => $share,
            'pay_day_no' => $this->payDayNumber($period['pay_day']),
            'basic_pay' => self::r2($basic),
            'paid_leave_pay' => self::r2($leavePay),
            'holiday_pay' => self::r2($holidayPay),
            'holiday_ot_pay' => self::r2($holidayOt),
            'overtime_pay' => self::r2($otPay),
            'night_diff_pay' => self::r2($nightPay),
            'late_deduction' => self::r2($lateDed),
            'absence_deduction' => self::r2($absence),
            'leave_deduction' => self::r2($leaveDed),
            'adjustments_total' => self::r2($adjTotal),
            'gross_pay' => self::r2($gross),
            'sss_deduction' => self::r2($sss),
            'philhealth_deduction' => self::r2($ph),
            'pagibig_deduction' => self::r2($hd),
            'tax_deduction' => self::r2($tax),
            'taxable_income' => self::r2($taxable),
            'carry_over_in' => self::r2($carryIn),
            'net_pay' => self::r2($net),
            'carry_over_out' => $s['negative'] === 'CARRY_OVER' ? self::r2($short) : 0.0,
            'employer_shouldered' => $s['negative'] === 'CARRY_OVER' ? 0.0 : self::r2($short),
            'steps' => implode(' | ', $steps),
        ];
    }
}
