<?php

namespace App\Support;

use App\Models\Employee;
use App\Models\LeaveRequest;
use Carbon\CarbonImmutable;

// Leave credits (QA review, 2026-11-11): once regular, an employee earns
// leave.vl_days_per_year (15) vacation and leave.sl_days_per_year (15) sick
// leave a year, a share of it for every pay period that ends. Emergency leave
// uses the VL credits. Credits do not carry over: the count starts again every
// January 1. Worked out when needed, so nothing has to run on a schedule. Paid
// days of approved leave in the year are subtracted from their own credits.
// The leave the law gives (maternity, paternity...) never uses credits.
class LeaveCredits
{
    // ['year', 'vacation' => [earned, used, available], 'sick' => [...],
    //  'earned', 'used', 'available' (both together)] (days)
    public static function for(Employee $employee, ?string $asOf = null): array
    {
        $today = CarbonImmutable::parse($asOf ?? now(config('atice.timezone'))->toDateString());
        $empty = ['earned' => 0.0, 'used' => 0.0, 'available' => 0.0];
        $out = ['year' => $today->year, 'vacation' => $empty, 'sick' => $empty];

        if ($employee->employment_status === 'regular' && $employee->regularized_at) {
            $from = CarbonImmutable::parse($employee->regularized_at)->max($today->startOfYear());
            $periods = 0;
            $p = PayPeriod::for($from->toDateString());
            while ($p['end'] <= $today->toDateString() && $periods < 30) {
                $periods++;
                $p = PayPeriod::for(CarbonImmutable::parse($p['end'])->addDay()->toDateString());
            }
            // pay periods in a year, from the period length (14 days: 26)
            $perYear = max(1, (int) round(365 / max(1, (int) Settings::get('payroll.cutoff_days'))));

            $used = LeaveRequest::where('employee_id', $employee->id)
                ->where('status', 'approved')
                ->whereYear('start_date', $today->year)
                ->whereIn('leave_type', array_keys(LeaveRequest::POOL))
                ->get(['leave_type', 'paid_days'])
                ->groupBy(fn ($l) => LeaveRequest::POOL[$l->leave_type])
                ->map(fn ($rows) => (float) $rows->sum('paid_days'));

            foreach (['vacation' => 'leave.vl_days_per_year', 'sick' => 'leave.sl_days_per_year'] as $pool => $key) {
                $year = (float) Settings::get($key);
                $earned = min($year, $periods * $year / $perYear);
                $spent = (float) ($used[$pool] ?? 0);
                $out[$pool] = ['earned' => round($earned, 2), 'used' => round($spent, 2), 'available' => round(max(0, $earned - $spent), 2)];
            }
        }

        foreach (['earned', 'used', 'available'] as $k) {
            $out[$k] = round($out['vacation'][$k] + $out['sick'][$k], 2);
        }

        return $out;
    }

    // The credits a type of leave uses ('vacation' or 'sick'), or null for leave the law gives.
    public static function pool(string $type): ?string
    {
        return LeaveRequest::POOL[$type] ?? null;
    }

    // A request's length in days: emergency leave is in hours.
    public static function days(LeaveRequest $leave): float
    {
        return $leave->leave_type === 'emergency' && $leave->hours !== null
            ? (float) $leave->hours / (float) Settings::get('payroll.hours_per_day')
            : (float) $leave->total_days;
    }
}
