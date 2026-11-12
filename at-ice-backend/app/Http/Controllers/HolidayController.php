<?php

namespace App\Http\Controllers;

use App\Models\Holiday;
use App\Support\Audit;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

// The holiday calendar (HR and Admin). Add, edit, delete, list by year.
class HolidayController extends Controller
{
    // Most a date range can cover (e.g. a Holy Week or a declared break).
    public const MAX_RANGE_DAYS = 31;

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

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $request->validate([
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date'],
        ], ['end_date.after_or_equal' => 'The end date must be on or after the start date.']);

        $from = Carbon::parse($data['date']);
        $to = Carbon::parse($request->input('end_date') ?: $data['date']);

        $days = (int) $from->diffInDays($to) + 1;
        if ($days > self::MAX_RANGE_DAYS) {
            throw ValidationException::withMessages(['end_date' => 'A holiday range can be at most '.self::MAX_RANGE_DAYS." days ({$days} days chosen)."]);
        }

        $dates = collect(CarbonPeriod::create($from, $to))->map(fn ($d) => $d->toDateString());
        $taken = Holiday::whereIn('date', $dates)->orderBy('date')->pluck('date')->map(fn ($d) => Carbon::parse($d)->format('M j, Y'));
        if ($taken->isNotEmpty()) {
            throw ValidationException::withMessages(['date' => 'There is already a holiday on '.$taken->implode(', ').'. Edit it instead, or pick other dates.']);
        }

        $holidays = DB::transaction(fn () => $dates->map(function ($date) use ($data, $request) {
            $holiday = Holiday::create(['date' => $date, 'year' => (int) substr($date, 0, 4)] + $data + ['created_by' => $request->user()->id]);
            Audit::log('holiday.added', $holiday, null, $holiday->only(['date', 'name', 'type', 'is_double']));

            return $holiday;
        }));

        // One date: the holiday itself (as before). A range: all of them.
        if ($holidays->count() === 1) {
            return response()->json($holidays->first(), 201);
        }

        return response()->json([
            'message' => "{$data['name']} was added for {$holidays->count()} days.",
            'holidays' => $holidays->values(),
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $holiday = Holiday::findOrFail($id);
        $before = $holiday->only(['date', 'name', 'type', 'is_double']);
        $holiday->update($this->validated($request, $holiday->id));
        Audit::log('holiday.changed', $holiday, $before, $holiday->only(['date', 'name', 'type', 'is_double']));

        return response()->json($holiday);
    }

    public function destroy($id)
    {
        $holiday = Holiday::findOrFail($id);
        $holiday->delete();
        Audit::log('holiday.removed', $holiday, $holiday->only(['date', 'name', 'type']), null);

        return response()->json(['message' => "{$holiday->name} was removed."]);
    }

    private function validated(Request $request, ?int $ignore = null): array
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d', Rule::unique('holidays', 'date')->ignore($ignore)],
            'name' => 'required|string|max:50',
            'type' => ['required', Rule::in(Holiday::TYPES)],
            'is_double' => 'nullable|boolean',
        ], [
            'date.unique' => 'There is already a holiday on this date. Edit it instead.',
        ]);

        return $data + ['year' => (int) substr($data['date'], 0, 4), 'is_double' => (bool) ($data['is_double'] ?? false)];
    }
}
