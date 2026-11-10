<?php

namespace App\Support;

use App\Models\Employee;

// Late minutes at Time In, counted in whole minutes from the shift start:
// late_minutes = floor((time_in - shift_start) / 60 seconds), so with a 09:00
// shift 09:00:59 is 0 minutes late and 09:01:00 is 1. Within the grace period
// (attendance.grace_minutes, default 0) it is not late at all. Automatic: the
// time comes from the server clock at the kiosk. Overnight shifts work: with a
// 22:00 start, a 00:30 Time In is 150 minutes late and 21:50 is on time.
class Lateness
{
    public static function minutes(string $shiftStart, string $timeIn, ?int $grace = null): int
    {
        $grace ??= (int) Settings::get('attendance.grace_minutes');
        $diff = self::seconds($timeIn) - self::seconds($shiftStart);
        // Across midnight. A Time In more than 12 hours before the clock time
        // of the start is a late arrival after midnight. One more than 21 hours
        // after it is an early arrival (up to 3 hours) for a start just after
        // midnight. Anything between is simply late, not early.
        if ($diff < -43200) {
            $diff += 86400;
        } elseif ($diff > 75600) {
            $diff -= 86400;
        }
        $late = intdiv($diff, 60);
        if ($late <= 0 || $late <= $grace) {
            return 0;
        }

        return $late;
    }

    // The shift start for an employee: their own time range (R3), or the
    // default of their old Day/Night label if it was never set.
    public static function shiftStart(?Employee $employee): ?string
    {
        if ($employee?->shift_start) {
            return substr($employee->shift_start, 0, 5);
        }

        return (string) Settings::get($employee?->shift === 'night' ? 'attendance.night_shift_start' : 'attendance.shift_start');
    }

    // "day" or "night" for a time range: night when it ends the next day.
    public static function label(string $start, string $end): string
    {
        return self::seconds($end) <= self::seconds($start) ? 'night' : 'day';
    }

    // Night-shift staff (HR and Admin employee lists): the shift crosses midnight,
    // or it is exactly the night hours in Settings.
    public static function isNight(?string $start, ?string $end, ?string $label = null): bool
    {
        if (! $start || ! $end) {
            return $label === 'night';
        }
        if (self::label($start, $end) === 'night') {
            return true;
        }
        $nightStart = (string) Settings::get('attendance.night_shift_start');
        $nightEnd = (string) Settings::get('attendance.night_shift_end');

        return substr($start, 0, 5) === substr($nightStart, 0, 5) && substr($end, 0, 5) === substr($nightEnd, 0, 5);
    }

    private static function seconds(string $time): int
    {
        [$h, $m, $s] = array_map('intval', array_pad(explode(':', $time), 3, 0));

        return $h * 3600 + $m * 60 + $s;
    }
}
