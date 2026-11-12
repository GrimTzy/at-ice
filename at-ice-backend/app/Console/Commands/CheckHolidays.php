<?php

namespace App\Console\Commands;

use App\Http\Controllers\HolidayController;
use App\Models\Holiday;
use App\Support\HolidayNames;
use App\Support\TextRules;
use Illuminate\Console\Command;

// Lists the holidays saved before the one-name-per-year rule (owner,
// 2026-11-12) that break it: repeats (the same name written differently,
// which saving now blocks) and possible repeats (1 or 2 letters apart, which
// the Holidays page only warns about), and names that would no longer be
// accepted (App\Support\HolidayNames, App\Support\TextRules).
// Also lists multi-day holidays (consecutive dates with one name) saved
// before holidays became one date each, for review.
// It changes nothing; remove or rename them on the Holidays page.
//   php artisan holidays:check
class CheckHolidays extends Command
{
    protected $signature = 'holidays:check';

    protected $description = 'List repeated and possibly repeated holiday names in a year, and junk names (read only)';

    public function handle(): int
    {
        $all = Holiday::orderBy('date')->get();

        // Repeats: the same name written differently (HolidayNames::key) in
        // one year, in more than one range. The days of one range
        // (consecutive dates) are one holiday.
        $repeats = [];
        // Multi-day holidays: consecutive dates with one name. A holiday is one
        // date now; only a declared Special (non-working) event may span days.
        $multi = [];
        foreach ($all->groupBy(fn ($h) => $h->year.'|'.HolidayController::nameKey($h->name)) as $rows) {
            $ranges = [];
            $prev = null;
            foreach ($rows as $h) {
                if ($prev && $prev->date->copy()->addDay()->isSameDay($h->date)) {
                    $ranges[count($ranges) - 1][] = $h;
                } else {
                    $ranges[] = [$h];
                }
                $prev = $h;
            }
            foreach ($ranges as $range) {
                if (count($range) > 1) {
                    $multi[] = [
                        $range[0]->year,
                        $range[0]->name,
                        $range[0]->date->format('M j').' to '.end($range)->date->format('M j'),
                        count($range),
                        implode(', ', array_unique(array_map(fn ($h) => ['REGULAR' => 'Regular', 'SPECIAL_NON_WORKING' => 'Special (non-working)', 'SPECIAL_WORKING' => 'Special working'][$h->type] ?? $h->type, $range))),
                        implode(', ', array_map(fn ($h) => $h->id, $range)),
                    ];
                }
            }
            if (count($ranges) > 1) {
                foreach ($ranges as $i => $range) {
                    $first = $range[0];
                    $repeats[] = [
                        $first->year,
                        $first->name,
                        $first->date->format('M j').(count($range) > 1 ? ' to '.end($range)->date->format('M j') : ''),
                        implode(', ', array_map(fn ($h) => $h->id, $range)),
                        $i === 0 ? 'keep (first)' : 'repeat',
                    ];
                }
            }
        }

        // Possible repeats: one row per name and year, then pairs 1 or 2 letters apart
        $possible = [];
        foreach ($all->groupBy('year') as $year => $rows) {
            $names = $rows->unique(fn ($h) => HolidayNames::key($h->name))->values();
            foreach ($names as $i => $a) {
                foreach ($names->slice($i + 1) as $b) {
                    if (HolidayNames::isClose($a->name, $b->name)) {
                        $possible[] = [$year, "{$a->name} ({$a->date->format('M j')})", "{$b->name} ({$b->date->format('M j')})"];
                    }
                }
            }
        }

        $junk = $all->map(fn ($h) => [$h, TextRules::problem($h->name, TextRules::NAME_CHARS, TextRules::HOLIDAY, TextRules::SHORT_WORD)])
            ->filter(fn ($x) => $x[1] !== null)
            ->map(fn ($x) => [$x[0]->id, $x[0]->date->toDateString(), mb_strimwidth($x[0]->name, 0, 60, '…'), $x[1]])
            ->values()->all();

        $this->info($all->count().' holidays checked. Nothing was changed.');
        if ($repeats) {
            $this->warn('Repeated names in the same year (the same name written differently; saving one now is blocked):');
            $this->table(['Year', 'Name', 'Dates', 'Row ids', ''], $repeats);
        } else {
            $this->info('No repeated names.');
        }
        if ($multi) {
            $this->warn('Multi-day holidays (consecutive days with one name; a holiday is one date now, review them):');
            $this->table(['Year', 'Name', 'Dates', 'Days', 'Type', 'Row ids'], $multi);
        } else {
            $this->info('No multi-day holidays.');
        }
        if ($possible) {
            $this->warn('Possible repeats (1 or 2 letters apart; check them, they may be different holidays):');
            $this->table(['Year', 'Holiday', 'Close to'], $possible);
        } else {
            $this->info('No possible repeats.');
        }
        if ($junk) {
            $this->warn('Names that are no longer accepted:');
            $this->table(['Id', 'Date', 'Name', 'Problem'], $junk);
        } else {
            $this->info('No junk names.');
        }

        return self::SUCCESS;
    }
}
