<?php

namespace Tests\Feature;

use App\Models\Holiday;
use App\Models\User;
use App\Support\HolidayNames;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Repeated holiday names (owner, 2026-11-12): "Chinese New Years" was saved
// next to "Chinese New Year". Sure duplicates are blocked; names 1 or 2
// letters apart only get a "Did you mean" note in the web app and can be
// saved. Real different holidays get neither.
class HolidayDuplicateLevelsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create(['role' => 'hr']));
        Holiday::create(['date' => '2028-02-17', 'year' => 2028, 'name' => 'Chinese New Year', 'type' => 'SPECIAL_NON_WORKING']);
    }

    public function test_the_same_name_written_differently_is_blocked(): void
    {
        $message = '"Chinese New Year" is already a holiday in 2028 (Feb 17). Edit that one instead.';
        foreach (['Chinese New Years', 'chinese new years', 'Chinese-New Year', 'Chinese New Year.', "Chinese New Year's", ' Chinese   New  Year '] as $i => $name) {
            $this->postJson('/api/holidays', ['date' => '2028-03-0'.($i + 1), 'name' => $name, 'type' => 'REGULAR'])
                ->assertUnprocessable()->assertJsonValidationErrors(['name' => $message]);
        }
        $this->assertSame(1, Holiday::where('year', 2028)->count());

        // editing another holiday into it is blocked too
        $other = Holiday::create(['date' => '2028-06-12', 'year' => 2028, 'name' => 'Independence Day', 'type' => 'REGULAR']);
        $this->putJson("/api/holidays/{$other->id}", ['date' => '2028-06-12', 'name' => 'Chinese New Years', 'type' => 'REGULAR'])
            ->assertUnprocessable()->assertJsonValidationErrors(['name' => $message]);
    }

    public function test_a_name_one_or_two_letters_apart_is_only_a_warning_and_can_be_saved(): void
    {
        foreach (['Chinese New Yaer', 'Chinese Nw Year', 'Chinese New Yeer'] as $name) {
            $this->assertTrue(HolidayNames::isClose($name, 'Chinese New Year'), $name);
        }
        $this->postJson('/api/holidays', ['date' => '2028-03-01', 'name' => 'Chinese New Yeer', 'type' => 'REGULAR'])->assertCreated();

        // short names (under 8 letters) get no note
        $this->assertFalse(HolidayNames::isClose('Eid Day', 'Eid Dax'));
    }

    public function test_real_different_holidays_get_neither(): void
    {
        $pairs = [['Christmas Eve', 'Christmas Day'], ['Maundy Thursday', 'Good Friday'], ['Good Friday', 'Black Saturday'],
            ['Maundy Thursday', 'Black Saturday'], ['Rizal Day', 'Bonifacio Day'], ["All Saints' Day", "All Souls' Day"], ["Eid'l Fitr", "Eid'l Adha"]];
        foreach ($pairs as [$a, $b]) {
            $this->assertFalse(HolidayNames::same($a, $b), "{$a} / {$b}");
            $this->assertFalse(HolidayNames::isClose($a, $b), "{$a} / {$b}");
        }

        // every holiday in the database (the seeded official list) against the others of its year
        foreach (Holiday::all()->groupBy('year') as $rows) {
            $names = $rows->pluck('name')->unique()->values();
            foreach ($names as $i => $a) {
                foreach ($names->slice($i + 1) as $b) {
                    $this->assertFalse(HolidayNames::same($a, $b), "{$a} / {$b}");
                    $this->assertFalse(HolidayNames::isClose($a, $b), "{$a} / {$b}");
                }
            }
        }

        // and they can all be added next to each other
        foreach (['Christmas Eve', 'Christmas Day', 'Maundy Thursday', 'Good Friday', 'Black Saturday', 'Rizal Day', 'Bonifacio Day'] as $i => $name) {
            $this->postJson('/api/holidays', ['date' => '2028-08-'.str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT), 'name' => $name, 'type' => 'REGULAR'])->assertCreated();
        }
    }

    // An older repeat later in the year is not added to the dates in the message.
    public function test_the_message_names_only_the_first_holidays_own_days(): void
    {
        Holiday::create(['date' => '2028-11-11', 'year' => 2028, 'name' => 'Chinese New Years', 'type' => 'REGULAR']);
        Holiday::create(['date' => '2028-11-12', 'year' => 2028, 'name' => 'Chinese New Years', 'type' => 'REGULAR']);

        $this->postJson('/api/holidays', ['date' => '2028-03-01', 'name' => 'Chinese New Year', 'type' => 'REGULAR'])
            ->assertUnprocessable()->assertJsonValidationErrors(['name' => '"Chinese New Year" is already a holiday in 2028 (Feb 17). Edit that one instead.']);
    }

    public function test_a_declared_multi_day_event_is_named_with_all_its_days(): void
    {
        foreach (['2028-04-10', '2028-04-11', '2028-04-12'] as $d) {
            $this->postJson('/api/holidays', ['date' => $d, 'name' => 'Plant Week', 'type' => 'SPECIAL_NON_WORKING'])->assertCreated();
        }
        $this->postJson('/api/holidays', ['date' => '2028-05-10', 'name' => 'Plant Weeks', 'type' => 'SPECIAL_NON_WORKING'])
            ->assertUnprocessable()->assertJsonValidationErrors(['name' => '"Plant Week" is already a holiday in 2028 (Apr 10 to Apr 12). Edit that one instead.']);
    }

    public function test_holidays_check_lists_both_levels_and_changes_nothing(): void
    {
        Holiday::create(['date' => '2028-11-11', 'year' => 2028, 'name' => 'Chinese New Years', 'type' => 'REGULAR']);
        Holiday::create(['date' => '2028-07-01', 'year' => 2028, 'name' => 'Company Foundation Day', 'type' => 'REGULAR']);
        Holiday::create(['date' => '2028-07-20', 'year' => 2028, 'name' => 'Company Fundation Day', 'type' => 'REGULAR']);

        $this->artisan('holidays:check')
            ->expectsOutputToContain('Repeated names in the same year (the same name written differently')
            ->expectsOutputToContain('Chinese New Years')
            ->expectsOutputToContain('Possible repeats (1 or 2 letters apart')
            ->expectsOutputToContain('Company Fundation Day')
            ->assertSuccessful();
        $this->assertSame(4, Holiday::where('year', 2028)->count());
    }
}
