<?php

namespace App\Http\Controllers;

use App\Models\Holiday;
use App\Support\Audit;
use App\Support\HolidayNames;
use App\Support\HolidayYear;
use App\Support\TextRules;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

// The holiday calendar (HR and Admin). Add, edit, delete, list by year.
// One holiday per date, and one use of a name per year (owner, 2026-11-12):
// a name that is the same as another holiday that year once case, . ' - ,
// extra spaces and a trailing "s" on each word are set aside is refused
// ("Chinese New Years" next to "Chinese New Year", App\Support\HolidayNames).
// A name only 1 or 2 letters away gets a "Did you mean" note in the web app
// but can be saved. A holiday is one date (owner, 2026-11-12: Proclamation
// No. 1006 lists every holiday as a single date), so there are no ranges. The
// one exception is a declared multi-day event (e.g. APEC 2015, Nov 18 and 19):
// the same name may be used again on the day right before or after a holiday
// with that name when both are Special (non-working). `php artisan
// holidays:check` lists repeats, possible repeats, multi-day holidays saved
// before this rule, and junk names.
// Dates (owner, 2026-11-12): a new holiday is today or later (Manila), and a
// holiday dated before today cannot be edited or removed, so attendance and
// payroll already counted do not change. "Generate 2027 holidays" carries the
// holidays marked "Repeats every year" over to next year (App\Support\HolidayYear).
class HolidayController extends Controller
{
    // GET /api/holidays?year=2026
    public function index(Request $request)
    {
        $year = (int) $request->input('year', now(config('atice.timezone'))->year);

        return response()->json([
            'year' => $year,
            'years' => Holiday::query()->distinct()->orderBy('year')->pluck('year'),
            'holidays' => Holiday::where('year', $year)->orderBy('date')->get(),
        ]);
    }

    // POST /api/holidays { date, name, type, is_double?, repeats_yearly? }
    // One date only: a range (end_date) is refused.
    public function store(Request $request)
    {
        $request->validate(['end_date' => ['prohibited']], ['end_date.prohibited' => 'A holiday is one date. Add each day on its own.']);
        $data = $this->validated($request);
        $this->refusePastDate($data['date']);
        $this->refuseRepeatedName($data['name'], $data['date'], $data['type']);

        $holiday = DB::transaction(function () use ($data, $request) {
            $holiday = Holiday::create($data + ['created_by' => $request->user()->id]);
            Audit::log('holiday.added', $holiday, null, $holiday->only(['date', 'name', 'type', 'is_double']));

            return $holiday;
        });

        return response()->json($holiday, 201);
    }

    public function update(Request $request, $id)
    {
        $holiday = Holiday::findOrFail($id);
        $this->refusePastHoliday($holiday, 'edited');
        $request->validate(['end_date' => ['prohibited']], ['end_date.prohibited' => 'A holiday is one date. Add each day on its own.']);
        $before = $holiday->only(['date', 'name', 'type', 'is_double', 'repeats_yearly']);
        $data = $this->validated($request, $holiday->id);
        if ($data['date'] !== $holiday->date->toDateString()) {
            $this->refusePastDate($data['date']);
        }
        $this->refuseRepeatedName($data['name'], $data['date'], $data['type'], $holiday->id);
        $holiday->update($data);
        Audit::log('holiday.changed', $holiday, $before, $holiday->only(['date', 'name', 'type', 'is_double', 'repeats_yearly']));

        return response()->json($holiday);
    }

    public function destroy($id)
    {
        $holiday = Holiday::findOrFail($id);
        $this->refusePastHoliday($holiday, 'removed');
        $holiday->delete();
        Audit::log('holiday.removed', $holiday, $holiday->only(['date', 'name', 'type']), null);

        return response()->json(['message' => "{$holiday->name} was removed."]);
    }

    private function validated(Request $request, ?int $ignore = null): array
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d', Rule::unique('holidays', 'date')->ignore($ignore)],
            'name' => ['required', ...TextRules::name(TextRules::HOLIDAY, 'Holiday name')],
            'type' => ['required', Rule::in(Holiday::TYPES)],
            'is_double' => 'nullable|boolean',
            'repeats_yearly' => 'nullable|boolean',
        ], [
            'date.unique' => 'There is already a holiday on this date. Edit it instead.',
        ]);

        // saved without extra spaces
        $data['name'] = preg_replace('/\s+/u', ' ', trim($data['name']));

        return ['year' => (int) substr($data['date'], 0, 4), 'is_double' => (bool) ($data['is_double'] ?? false),
            // new holidays repeat every year unless unticked
            'repeats_yearly' => (bool) ($data['repeats_yearly'] ?? true)] + $data;
    }

    private static function today(): string
    {
        return now(config('atice.timezone'))->toDateString();
    }

    private function refusePastDate(string $date, string $field = 'date'): void
    {
        if ($date < self::today()) {
            throw ValidationException::withMessages([$field => 'Holiday date must be today or later.']);
        }
    }

    // Attendance and payroll have already counted a holiday dated before today.
    private function refusePastHoliday(Holiday $holiday, string $what): void
    {
        if ($holiday->date->toDateString() < self::today()) {
            throw ValidationException::withMessages(['date' => "{$holiday->name} ({$holiday->date->format('M j, Y')}) is in the past, so it cannot be {$what}. Attendance and payroll have already counted it."]);
        }
    }

    private static function nextYearNumber(): int
    {
        return now(config('atice.timezone'))->year + 1;
    }

    // GET /api/holidays/next-year: the preview of next year's holidays
    // (App\Support\HolidayYear::preview). Saves nothing.
    public function nextYear()
    {
        $year = self::nextYearNumber();

        return response()->json(['year' => $year, 'from_year' => $year - 1, 'rows' => HolidayYear::preview($year)]);
    }

    // POST /api/holidays/next-year { holidays: [{ name, type, is_double?, date }] }
    // Saves the rows HR kept from the preview, all or none, with the same
    // rules as adding one (one date each, name limits, one name per year with
    // the multi-day event exception, one holiday per date).
    public function saveNextYear(Request $request)
    {
        $year = self::nextYearNumber();
        $request->validate([
            'holidays' => ['required', 'array', 'min:1', 'max:60'],
            'holidays.*.name' => ['required', ...TextRules::name(TextRules::HOLIDAY, 'Holiday name')],
            'holidays.*.type' => ['required', Rule::in(Holiday::TYPES)],
            'holidays.*.is_double' => ['nullable', 'boolean'],
            'holidays.*.date' => ['required', 'date_format:Y-m-d'],
            'holidays.*.end_date' => ['prohibited'],
        ], [
            'holidays.required' => 'Tick at least one holiday to add.',
            'holidays.min' => 'Tick at least one holiday to add.',
            'holidays.*.date.required' => 'Set the date, or untick this holiday.',
            'holidays.*.end_date.prohibited' => 'A holiday is one date.',
        ]);

        $rows = [];
        $usedDates = [];
        foreach (array_values($request->input('holidays')) as $i => $in) {
            $name = preg_replace('/\s+/u', ' ', trim($in['name']));
            $date = $in['date'];
            if ((int) substr($date, 0, 4) !== $year) {
                throw ValidationException::withMessages(["holidays.{$i}.date" => "{$name}: the date must be in {$year}."]);
            }
            $this->refusePastDate($date, "holidays.{$i}.date");
            if (isset($usedDates[$date])) {
                throw ValidationException::withMessages(["holidays.{$i}.date" => "{$name} and {$usedDates[$date]} are both on ".Carbon::parse($date)->format('M j, Y').'. Change one date.']);
            }
            $usedDates[$date] = $name;
            $key = self::nameKey($name);
            $sameInList = collect($rows)->filter(fn ($r) => self::nameKey($r['name']) === $key);
            if ($sameInList->isNotEmpty() && ! self::nextToSpecial($sameInList, $date, $in['type'])) {
                throw ValidationException::withMessages(["holidays.{$i}.name" => "\"{$name}\" is in the list twice. Untick one."]);
            }
            $this->refuseRepeatedName($name, $date, $in['type'], null, "holidays.{$i}.name");
            $taken = Holiday::where('date', $date)->first();
            if ($taken) {
                throw ValidationException::withMessages(["holidays.{$i}.date" => "{$name}: there is already a holiday on {$taken->date->format('M j, Y')} ({$taken->name}). Pick another date or untick it."]);
            }
            $rows[] = ['name' => $name, 'type' => $in['type'], 'is_double' => (bool) ($in['is_double'] ?? false), 'date' => $date];
        }

        DB::transaction(function () use ($rows, $year, $request) {
            foreach ($rows as $row) {
                $holiday = Holiday::create($row + ['year' => $year, 'repeats_yearly' => true, 'created_by' => $request->user()->id]);
                Audit::log('holiday.added', $holiday, null, $holiday->only(['date', 'name', 'type', 'is_double']));
            }
        });

        $count = count($rows);

        return response()->json([
            'message' => "{$count} holiday".($count === 1 ? ' was' : 's were')." added to {$year}.",
            'year' => $year, 'added' => $count,
        ], 201);
    }

    // "  Chinese  New-Years " -> "chinese new year" (App\Support\HolidayNames::key)
    public static function nameKey(?string $name): string
    {
        return HolidayNames::key($name);
    }

    // The name is already a holiday in that year (other than $ignore), and
    // this is not the next or previous day of a declared multi-day event.
    private function refuseRepeatedName(string $name, string $date, string $type, ?int $ignore = null, string $field = 'name'): void
    {
        $key = self::nameKey($name);
        $same = Holiday::where('year', (int) substr($date, 0, 4))->when($ignore, fn ($q) => $q->where('id', '!=', $ignore))
            ->orderBy('date')->get()->filter(fn ($h) => self::nameKey($h->name) === $key)->values();
        if ($same->isEmpty() || self::nextToSpecial($same, $date, $type)) {
            return;
        }
        $first = $same->first();
        // the first holiday's own consecutive days, not an older repeat later that year
        $dates = collect([$first]);
        foreach ($same->slice(1) as $h) {
            if (! $dates->last()->date->copy()->addDay()->isSameDay($h->date)) {
                break;
            }
            $dates->push($h);
        }
        $days = $dates->count() > 2
            ? $dates->first()->date->format('M j').' to '.$dates->last()->date->format('M j')
            : $dates->map(fn ($h) => $h->date->format('M j'))->implode(', ');
        throw ValidationException::withMessages([$field => "\"{$first->name}\" is already a holiday in {$first->year} ({$days}). Edit that one instead."]);
    }

    // A declared multi-day event: the new holiday is Special (non-working) and
    // falls the day right before or after a Special (non-working) holiday
    // with the same name ($same: holidays or rows with `date` and `type`).
    public static function nextToSpecial($same, string $date, string $type): bool
    {
        if ($type !== 'SPECIAL_NON_WORKING') {
            return false;
        }
        $d = Carbon::parse($date);
        $around = [$d->copy()->subDay()->toDateString(), $d->copy()->addDay()->toDateString()];

        return collect($same)->contains(fn ($h) => data_get($h, 'type') === 'SPECIAL_NON_WORKING'
            && in_array(Carbon::parse(data_get($h, 'date'))->toDateString(), $around, true));
    }
}
