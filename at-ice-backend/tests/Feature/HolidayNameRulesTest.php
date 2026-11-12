<?php

namespace Tests\Feature;

use App\Models\Holiday;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Holidays must not repeat (owner, 2026-11-12): one holiday per date, and one
// use of a name per year, ignoring case and extra spaces, on add, edit and the
// From/To range. The days of one range share its name. Names follow
// App\Support\TextRules (50 characters, no keyboard mashing).
class HolidayNameRulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create(['role' => 'hr']));
    }

    public function test_the_same_name_twice_in_a_year_is_refused(): void
    {
        $this->postJson('/api/holidays', ['date' => '2028-01-26', 'name' => 'Chinese New Year', 'type' => 'SPECIAL_NON_WORKING'])->assertCreated();

        $this->postJson('/api/holidays', ['date' => '2028-02-10', 'name' => '  chinese   NEW year ', 'type' => 'SPECIAL_NON_WORKING'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name' => '"Chinese New Year" is already a holiday in 2028 (Jan 26). Edit that one instead.']);

        // the next day too, unless both are Special (non-working) (a declared multi-day event)
        $this->postJson('/api/holidays', ['date' => '2028-01-27', 'name' => 'Chinese New Year', 'type' => 'REGULAR'])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertSame(1, Holiday::where('year', 2028)->count());

        // another year is fine, and the name is saved without the extra spaces
        $this->postJson('/api/holidays', ['date' => '2029-02-13', 'name' => '  Chinese   New Year ', 'type' => 'SPECIAL_NON_WORKING'])
            ->assertCreated()->assertJsonPath('name', 'Chinese New Year');
    }

    public function test_editing_cannot_take_a_name_used_elsewhere_in_the_year(): void
    {
        Holiday::create(['date' => '2028-01-26', 'year' => 2028, 'name' => 'Chinese New Year', 'type' => 'SPECIAL_NON_WORKING']);
        $other = Holiday::create(['date' => '2028-06-12', 'year' => 2028, 'name' => 'Independence Day', 'type' => 'REGULAR']);

        $this->putJson("/api/holidays/{$other->id}", ['date' => '2028-06-12', 'name' => 'CHINESE NEW YEAR', 'type' => 'REGULAR'])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        // keeping its own name is fine
        $this->putJson("/api/holidays/{$other->id}", ['date' => '2028-06-12', 'name' => 'Independence Day', 'type' => 'REGULAR'])->assertOk();
    }

    public function test_the_days_of_a_declared_event_share_the_name_and_can_be_edited(): void
    {
        foreach (['2028-11-17', '2028-11-18', '2028-11-19'] as $d) {
            $this->postJson('/api/holidays', ['date' => $d, 'name' => 'APEC Summit', 'type' => 'SPECIAL_NON_WORKING'])->assertCreated();
        }
        $middle = Holiday::where('date', '2028-11-18')->value('id');

        $this->putJson("/api/holidays/{$middle}", ['date' => '2028-11-18', 'name' => 'APEC Summit', 'type' => 'SPECIAL_NON_WORKING', 'is_double' => true])->assertOk();
        // made Regular, it is no longer allowed next to the others
        $this->putJson("/api/holidays/{$middle}", ['date' => '2028-11-18', 'name' => 'APEC Summit', 'type' => 'REGULAR'])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertSame(3, Holiday::where('name', 'APEC Summit')->count());
    }

    public function test_long_or_junk_names_are_refused(): void
    {
        // up to 50 characters (owner, 2026-11-12), so the official name fits
        $official = 'Feast of the Immaculate Conception of Mary'; // 42 characters
        $this->postJson('/api/holidays', ['date' => '2028-12-08', 'name' => $official, 'type' => 'SPECIAL_NON_WORKING'])->assertCreated();
        $fiftyOne = 'Feast of the Immaculate Conception of the Blessed V'; // 51 characters
        $this->assertSame(51, strlen($fiftyOne));
        $this->postJson('/api/holidays', ['date' => '2028-12-09', 'name' => $fiftyOne, 'type' => 'SPECIAL_NON_WORKING'])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/holidays', ['date' => '2028-12-10', 'name' => 'QA-TEST LLLLLLLLLLLLLLLLLLLLLLLLLLLL', 'type' => 'REGULAR'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name' => 'Check the spelling of the holiday name. A word in it is too long or repeats the same letters.']);
        $this->postJson('/api/holidays', ['date' => '2028-12-10', 'name' => 'Holiday <script>', 'type' => 'REGULAR'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name' => 'Holiday name can only include letters, numbers, spaces, periods, commas, apostrophes, hyphens, ampersands (&) and brackets.']);
        $this->postJson('/api/holidays', ['date' => '2028-12-10', 'name' => "Eid'l Adha (Feast of Sacrifice)", 'type' => 'REGULAR'])->assertCreated();
    }

    public function test_the_check_command_lists_repeats_and_junk_and_changes_nothing(): void
    {
        Holiday::create(['date' => '2028-01-26', 'year' => 2028, 'name' => 'Chinese New Year', 'type' => 'SPECIAL_NON_WORKING']);
        Holiday::create(['date' => '2028-02-10', 'year' => 2028, 'name' => 'chinese new year', 'type' => 'SPECIAL_NON_WORKING']);
        Holiday::create(['date' => '2028-04-10', 'year' => 2028, 'name' => 'Holy Week', 'type' => 'REGULAR']);
        Holiday::create(['date' => '2028-04-11', 'year' => 2028, 'name' => 'Holy Week', 'type' => 'REGULAR']);
        Holiday::create(['date' => '2028-05-05', 'year' => 2028, 'name' => 'asdfghjklasdfghjklasdfghjkl', 'type' => 'REGULAR']);

        $this->artisan('holidays:check')
            ->expectsOutputToContain('Repeated names in the same year (the same name written differently')
            ->expectsOutputToContain('chinese new year')
            ->expectsOutputToContain('A word in it is too long or repeats the same letters.')
            // consecutive days with one name are not a repeat, but are listed for review
            ->expectsOutputToContain('Multi-day holidays (consecutive days with one name')
            ->expectsOutputToContain('Holy Week')
            ->assertSuccessful();
        $this->assertSame(5, Holiday::where('year', 2028)->count());
    }
}
