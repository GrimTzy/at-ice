<?php

namespace Tests\Feature;

use App\Models\Holiday;
use App\Models\User;
use App\Support\HolidayYear;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Holiday dates and next year's holidays (owner, 2026-11-12):
//  - a new holiday is today or later (Manila date); holidays dated before
//    today cannot be edited or removed (attendance and payroll counted them)
//  - "Generate 2027 holidays": a preview of the holidays marked "Repeats
//    every year" (fixed date, from Easter, last Monday of August, or left
//    empty for moving ones), saved only when HR presses Save.
class HolidayDatesAndNextYearTest extends TestCase
{
    use RefreshDatabase;

    private function at(string $manila): void
    {
        $this->travelTo(Carbon::parse($manila, 'Asia/Manila'));
    }

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create(['role' => 'hr']));
    }

    public function test_a_new_holiday_must_be_today_or_later_in_manila(): void
    {
        // 2027-03-09 17:30 UTC is already Mar 10 in Manila
        $this->travelTo(Carbon::parse('2027-03-09 17:30', 'UTC'));
        $this->postJson('/api/holidays', ['date' => '2027-03-09', 'name' => 'Plant Day', 'type' => 'REGULAR'])
            ->assertUnprocessable()->assertJsonValidationErrors(['date' => 'Holiday date must be today or later.']);
        $this->postJson('/api/holidays', ['date' => '2027-03-10', 'name' => 'Plant Day', 'type' => 'REGULAR'])->assertCreated();
        $this->assertSame(1, Holiday::where('year', 2027)->count());
    }

    public function test_holidays_before_today_cannot_be_edited_or_removed(): void
    {
        $this->at('2026-11-11 10:00');
        $past = Holiday::where('date', '2026-11-02')->first();          // All Souls' Day
        $today = Holiday::create(['date' => '2026-11-11', 'year' => 2026, 'name' => 'Plant Day', 'type' => 'REGULAR']);
        $future = Holiday::where('date', '2026-12-25')->first();

        $locked = "All Souls' Day (Nov 2, 2026) is in the past, so it cannot be edited. Attendance and payroll have already counted it.";
        $this->putJson("/api/holidays/{$past->id}", ['date' => '2026-11-02', 'name' => 'Souls Day', 'type' => 'REGULAR'])
            ->assertUnprocessable()->assertJsonValidationErrors(['date' => $locked]);
        $this->deleteJson("/api/holidays/{$past->id}")
            ->assertUnprocessable()->assertJsonValidationErrors(['date' => str_replace('edited', 'removed', $locked)]);
        $this->assertSame("All Souls' Day", $past->fresh()->name);

        // today and later can be changed, but not moved into the past
        $this->putJson("/api/holidays/{$today->id}", ['date' => '2026-11-11', 'name' => 'Plant Day', 'type' => 'SPECIAL_NON_WORKING'])->assertOk();
        $this->putJson("/api/holidays/{$future->id}", ['date' => '2026-11-10', 'name' => 'Christmas Day', 'type' => 'REGULAR'])
            ->assertUnprocessable()->assertJsonValidationErrors(['date' => 'Holiday date must be today or later.']);
        $this->deleteJson("/api/holidays/{$today->id}")->assertOk();
    }

    public function test_repeats_every_year_defaults_on_and_is_set_for_the_saved_holidays(): void
    {
        $this->at('2026-11-11 10:00');
        // the seeded Philippine holidays repeat
        $this->assertSame(0, Holiday::where('year', 2026)->where('repeats_yearly', false)->count());
        foreach (["New Year's Day", 'Chinese New Year', "Eid'l Adha", 'Good Friday', 'National Heroes Day', 'Rizal Day'] as $name) {
            $this->assertTrue(HolidayYear::isOfficial($name), $name);
        }
        foreach (['QA-TEST holiday', 'Special Non-Working Day in Bulacan', 'Plant Day'] as $name) {
            $this->assertFalse(HolidayYear::isOfficial($name), $name);
        }

        $this->postJson('/api/holidays', ['date' => '2026-12-01', 'name' => 'Plant Day', 'type' => 'REGULAR'])
            ->assertCreated()->assertJsonPath('repeats_yearly', true);
        $this->postJson('/api/holidays', ['date' => '2026-12-02', 'name' => 'Declared Special Day', 'type' => 'SPECIAL_NON_WORKING', 'repeats_yearly' => false])
            ->assertCreated()->assertJsonPath('repeats_yearly', false);

        // edited off
        $id = Holiday::where('name', 'Plant Day')->value('id');
        $this->putJson("/api/holidays/{$id}", ['date' => '2026-12-01', 'name' => 'Plant Day', 'type' => 'REGULAR', 'repeats_yearly' => false])
            ->assertOk()->assertJsonPath('repeats_yearly', false);
    }

    public function test_easter_and_national_heroes_day(): void
    {
        $this->assertSame('2026-04-05', HolidayYear::easter(2026)->toDateString());
        $this->assertSame('2027-03-28', HolidayYear::easter(2027)->toDateString());
        $this->assertSame('2028-04-16', HolidayYear::easter(2028)->toDateString());
        $this->assertSame('2026-08-31', HolidayYear::heroesDay(2026)->toDateString());
        $this->assertSame('2027-08-30', HolidayYear::heroesDay(2027)->toDateString());
        $this->assertSame('2028-08-28', HolidayYear::heroesDay(2028)->toDateString());
    }

    public function test_the_2027_preview_saves_nothing(): void
    {
        $this->at('2026-11-11 10:00');
        Holiday::create(['date' => '2026-11-20', 'year' => 2026, 'name' => 'Declared Special Day', 'type' => 'SPECIAL_NON_WORKING', 'repeats_yearly' => false]);
        foreach (['2026-12-14', '2026-12-15', '2026-12-16'] as $d) {
            Holiday::create(['date' => $d, 'year' => 2026, 'name' => 'Plant Break', 'type' => 'SPECIAL_NON_WORKING']);
        }
        // already in 2027, and a date already taken in 2027
        Holiday::create(['date' => '2027-12-25', 'year' => 2027, 'name' => 'Christmas Day', 'type' => 'REGULAR']);
        Holiday::create(['date' => '2027-06-12', 'year' => 2027, 'name' => 'Plant Anniversary', 'type' => 'REGULAR']);

        $res = $this->getJson('/api/holidays/next-year')->assertOk()->assertJsonPath('year', 2027)->assertJsonPath('from_year', 2026);
        $all = collect($res->json('rows'));
        $rows = $all->keyBy('name');

        // fixed dates: same month and day
        foreach (["New Year's Day" => '2027-01-01', 'Araw ng Kagitingan' => '2027-04-09', 'Labor Day' => '2027-05-01', 'Ninoy Aquino Day' => '2027-08-21',
            "All Saints' Day" => '2027-11-01', 'Bonifacio Day' => '2027-11-30', 'Feast of the Immaculate Conception of Mary' => '2027-12-08',
            'Christmas Eve' => '2027-12-24', 'Rizal Day' => '2027-12-30', 'Last Day of the Year' => '2027-12-31'] as $name => $date) {
            $this->assertSame([$date, 'fixed', null], [$rows[$name]['date'], $rows[$name]['rule'], $rows[$name]['skip']], $name);
        }
        // from Easter (Mar 28, 2027) and the last Monday of August
        $this->assertSame('2027-03-25', $rows['Maundy Thursday']['date']);
        $this->assertSame('2027-03-26', $rows['Good Friday']['date']);
        $this->assertSame('2027-03-27', $rows['Black Saturday']['date']);
        $this->assertSame('2027-08-30', $rows['National Heroes Day']['date']);
        // moving: no date, set it from the proclamation
        foreach (['Chinese New Year', "Eid'l Fitr"] as $name) {
            $this->assertSame([null, 'moving', 'Set the date from the official proclamation'], [$rows[$name]['date'], $rows[$name]['rule'], $rows[$name]['note']]);
        }
        // one row per holiday per date: no ranges
        $this->assertSame(['2027-12-14', '2027-12-15', '2027-12-16'], $all->where('name', 'Plant Break')->pluck('date')->values()->all());
        $this->assertFalse($all->contains(fn ($r) => array_key_exists('end_date', $r) || array_key_exists('days', $r)));
        // shown and skipped
        $this->assertSame('duplicate', $rows['Christmas Day']['skip']);
        $this->assertSame('Already in 2027 (Dec 25). Skipped.', $rows['Christmas Day']['note']);
        $this->assertSame('taken', $rows['Independence Day']['skip']);
        // one-time holidays are never generated
        $this->assertFalse($rows->has('Declared Special Day'));

        $this->assertSame(2, Holiday::where('year', 2027)->count());
    }

    public function test_saving_the_rows_hr_kept(): void
    {
        $this->at('2026-11-11 10:00');
        $rows = collect($this->getJson('/api/holidays/next-year')->json('rows'))->keyBy('name');
        $pick = fn ($name, $date = null) => ['name' => $name, 'type' => $rows[$name]['type'], 'is_double' => false, 'date' => $date ?? $rows[$name]['date']];

        // a moving holiday needs its date
        $this->postJson('/api/holidays/next-year', ['holidays' => [$pick("New Year's Day"), $pick('Chinese New Year')]])
            ->assertUnprocessable()->assertJsonValidationErrors(['holidays.1.date' => 'Set the date, or untick this holiday.']);
        // dates outside 2027, or on the same day as another row
        $this->postJson('/api/holidays/next-year', ['holidays' => [$pick('Chinese New Year', '2028-02-06')]])
            ->assertUnprocessable()->assertJsonValidationErrors(['holidays.0.date' => 'Chinese New Year: the date must be in 2027.']);
        $this->postJson('/api/holidays/next-year', ['holidays' => [$pick("New Year's Day"), $pick('Chinese New Year', '2027-01-01')]])
            ->assertUnprocessable()->assertJsonValidationErrors('holidays.1.date');
        // a name already in 2027 (written differently) is refused
        Holiday::create(['date' => '2027-02-06', 'year' => 2027, 'name' => 'Chinese New Year', 'type' => 'SPECIAL_NON_WORKING']);
        $this->postJson('/api/holidays/next-year', ['holidays' => [$pick("New Year's Day"), ['name' => 'Chinese New Years', 'type' => 'REGULAR', 'date' => '2027-02-07']]])
            ->assertUnprocessable()->assertJsonValidationErrors(['holidays.1.name' => '"Chinese New Year" is already a holiday in 2027 (Feb 6). Edit that one instead.']);
        $this->assertSame(1, Holiday::where('year', 2027)->count());   // nothing saved

        // a range is refused
        $this->postJson('/api/holidays/next-year', ['holidays' => [$pick("New Year's Day") + ['end_date' => '2027-01-02']]])
            ->assertUnprocessable()->assertJsonValidationErrors(['holidays.0.end_date' => 'A holiday is one date.']);

        $this->postJson('/api/holidays/next-year', ['holidays' => [$pick("New Year's Day"), $pick('Good Friday'), $pick("Eid'l Fitr", '2027-03-10'), $pick('National Heroes Day')]])
            ->assertCreated()->assertJsonPath('message', '4 holidays were added to 2027.');
        $this->assertSame('Good Friday', Holiday::forDate('2027-03-26')->name);
        $this->assertSame("Eid'l Fitr", Holiday::forDate('2027-03-10')->name);
        $this->assertTrue(Holiday::forDate('2027-08-30')->repeats_yearly);

        // the next preview shows them as already in 2027
        $again = collect($this->getJson('/api/holidays/next-year')->json('rows'))->keyBy('name');
        $this->assertSame('duplicate', $again['Good Friday']['skip']);
    }

    public function test_generating_2028_from_2027(): void
    {
        $this->at('2027-06-01 10:00');
        foreach ([['2027-03-25', 'Maundy Thursday'], ['2027-03-26', 'Good Friday'], ['2027-03-27', 'Black Saturday'], ['2027-08-30', 'National Heroes Day'], ['2027-06-12', 'Independence Day']] as [$d, $n]) {
            Holiday::create(['date' => $d, 'year' => 2027, 'name' => $n, 'type' => 'REGULAR']);
        }
        $rows = collect($this->getJson('/api/holidays/next-year')->assertJsonPath('year', 2028)->json('rows'))->pluck('date', 'name');
        $this->assertSame(['Maundy Thursday' => '2028-04-13', 'Good Friday' => '2028-04-14', 'Black Saturday' => '2028-04-15',
            'Independence Day' => '2028-06-12', 'National Heroes Day' => '2028-08-28'], $rows->all());
    }

    public function test_only_hr_and_admin_generate(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'employee', 'sub_role' => 'checker']));
        $this->getJson('/api/holidays/next-year')->assertForbidden();
        $this->postJson('/api/holidays/next-year', ['holidays' => []])->assertForbidden();
    }
}
