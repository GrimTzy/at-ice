<?php

namespace App\Support;

use App\Http\Controllers\HolidayController;
use App\Models\Holiday;
use Carbon\Carbon;

// Next year's holidays from this year's (owner, 2026-11-12). HR presses
// "Generate 2027 holidays", checks the preview and saves
// (HolidayController@nextYear / saveNextYear). Only holidays marked
// "Repeats every year" are carried over, one row per holiday date. How each
// date is found:
//   fixed   same month and day next year (New Year's Day, Araw ng Kagitingan,
//           Labor Day, Independence Day, Christmas, Rizal Day, ... and any
//           other repeating holiday not listed below)
//   easter  from next year's Easter Sunday: Maundy Thursday (-3 days),
//           Good Friday (-2), Black Saturday (-1)
//   heroes  National Heroes Day: the last Monday of August
//   moving  no fixed rule (Chinese New Year, Eid'l Fitr, Eid'l Adha): left
//           empty, HR sets the date from the official proclamation or skips it
class HolidayYear
{
    // Philippine regular and special holidays (marked "Repeats every year"
    // when the column was added; other saved names were marked one-time).
    public const OFFICIAL = [
        "New Year's Day", 'Chinese New Year', 'EDSA People Power Revolution Anniversary', 'Maundy Thursday',
        'Good Friday', 'Black Saturday', 'Easter Sunday', 'Araw ng Kagitingan', 'Labor Day', 'Independence Day',
        "Eid'l Fitr", "Eid'l Adha", 'Ninoy Aquino Day', 'National Heroes Day', "All Saints' Day", "All Saints' Day Eve",
        "All Souls' Day", 'Bonifacio Day', 'Feast of the Immaculate Conception of Mary', 'Christmas Eve',
        'Christmas Day', 'Rizal Day', 'Last Day of the Year',
    ];

    // days from Easter Sunday, by HolidayNames::key
    private const FROM_EASTER = [
        'maundy thursday' => -3, 'holy thursday' => -3, 'good friday' => -2,
        'black saturday' => -1, 'holy saturday' => -1, 'easter sunday' => 0, 'easter' => 0,
    ];

    // moving dates (lunar or Islamic calendar), by words in the name key
    private const MOVING = '/\b(eid|eidl|eidul|chinese new year|lunar new year|hari raya|ramadan|islamic new year)\b/u';

    public static function isOfficial(?string $name): bool
    {
        $key = HolidayNames::key($name);
        foreach (self::OFFICIAL as $official) {
            if (HolidayNames::key($official) === $key) {
                return true;
            }
        }

        return self::rule($name) !== 'fixed';
    }

    // 'easter' | 'heroes' | 'moving' | 'fixed'
    public static function rule(?string $name): string
    {
        $key = HolidayNames::key($name);

        return match (true) {
            array_key_exists($key, self::FROM_EASTER) => 'easter',
            $key === HolidayNames::key('National Heroes Day') => 'heroes',
            (bool) preg_match(self::MOVING, $key) => 'moving',
            default => 'fixed',
        };
    }

    // Easter Sunday (Gregorian calendar; anonymous Gregorian algorithm).
    // 2026 Apr 5, 2027 Mar 28, 2028 Apr 16.
    public static function easter(int $year): Carbon
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $n = $h + $l - 7 * $m + 114;

        return Carbon::create($year, intdiv($n, 31), $n % 31 + 1)->startOfDay();
    }

    // The last Monday of August.
    public static function heroesDay(int $year): Carbon
    {
        $day = Carbon::create($year, 8, 31)->startOfDay();

        return $day->isMonday() ? $day : $day->previous(Carbon::MONDAY);
    }

    // The preview for $year: one row per repeating holiday of $year - 1, one
    // per date (a holiday is one date; Holy Week days are separate rows). A
    // name with a computed or moving date gets one row even if it was saved
    // twice. Rows already in $year are marked `duplicate` and skipped; a date
    // another holiday already has is `taken`.
    public static function preview(int $year): array
    {
        $existing = Holiday::where('year', $year)->orderBy('date')->get();
        $byDate = $existing->keyBy(fn ($h) => $h->date->toDateString());

        $rows = [];
        $seen = [];
        foreach (Holiday::where('year', $year - 1)->where('repeats_yearly', true)->orderBy('date')->get() as $h) {
            $key = HolidayNames::key($h->name);
            $rule = self::rule($h->name);
            if ($rule !== 'fixed' && isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $date = match ($rule) {
                'easter' => self::easter($year)->addDays(self::FROM_EASTER[$key]),
                'heroes' => self::heroesDay($year),
                'moving' => null,
                default => $h->date->month === 2 && $h->date->day === 29 && ! Carbon::create($year)->isLeapYear()
                    ? null : Carbon::create($year, $h->date->month, $h->date->day)->startOfDay(),
            };
            $note = match ($rule) {
                'easter' => 'Easter Sunday is '.self::easter($year)->format('M j, Y'),
                'moving' => 'Set the date from the official proclamation',
                default => $date ? 'Was '.$h->date->format('M j, Y') : "Feb 29 is not in {$year}. Set the date.",
            };
            $row = [
                'name' => $h->name, 'type' => $h->type, 'is_double' => (bool) $h->is_double,
                'rule' => $rule, 'note' => $note, 'from' => $h->date->toDateString(),
                'date' => $date?->toDateString(), 'skip' => null,
            ];

            // already in $year: the same name, unless this is the next or
            // previous day of a declared multi-day event (HolidayController::nextToSpecial)
            $same = $existing->filter(fn ($e) => HolidayNames::key($e->name) === $key)->values();
            $onThisDate = $date ? $same->first(fn ($e) => $e->date->toDateString() === $row['date']) : null;
            if ($same->isNotEmpty() && ($onThisDate || ! $date || ! HolidayController::nextToSpecial($same, $row['date'], $h->type))) {
                $first = $onThisDate ?? $same->first();
                $row['skip'] = 'duplicate';
                $row['note'] = "Already in {$year} ({$first->date->format('M j')}). Skipped.";
            } elseif ($date && ($other = $byDate->get($row['date']))) {
                $row['skip'] = 'taken';
                $row['note'] = "{$date->format('M j')} already has \"{$other->name}\". Pick another date or leave it unticked.";
            }
            $rows[] = $row;
        }

        // by date; the ones without a date last
        usort($rows, fn ($a, $b) => [$a['date'] === null, $a['date'] ?? $a['from']] <=> [$b['date'] === null, $b['date'] ?? $b['from']]);

        return $rows;
    }
}
