<?php

namespace Tests\Feature;

use App\Models\Holiday;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// A holiday is one date (owner, 2026-11-12: Proclamation No. 1006 lists every
// holiday as a single date). Ranges are refused. A declared multi-day event
// (e.g. APEC 2015, Nov 18 and 19) is added a day at a time: the same name is
// allowed again only on the day right before or after a holiday with that
// name, and only when both are Special (non-working).
class HolidayRangeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2028-01-05 09:00', 'Asia/Manila'));
        Sanctum::actingAs(User::factory()->create(['role' => 'hr']));
    }

    public function test_a_range_is_refused(): void
    {
        $this->postJson('/api/holidays', ['date' => '2028-04-10', 'end_date' => '2028-04-14', 'name' => 'Plant maintenance break', 'type' => 'SPECIAL_NON_WORKING'])
            ->assertUnprocessable()->assertJsonValidationErrors(['end_date' => 'A holiday is one date. Add each day on its own.']);
        $this->assertSame(0, Holiday::where('year', 2028)->count());

        $h = $this->postJson('/api/holidays', ['date' => '2028-04-10', 'name' => 'Plant Day', 'type' => 'REGULAR'])->assertCreated();
        $this->putJson('/api/holidays/'.$h->json('id'), ['date' => '2028-04-10', 'end_date' => '2028-04-11', 'name' => 'Plant Day', 'type' => 'REGULAR'])
            ->assertUnprocessable()->assertJsonValidationErrors('end_date');

        // one holiday per date still holds
        $this->postJson('/api/holidays', ['date' => '2028-04-10', 'name' => 'Another', 'type' => 'REGULAR'])
            ->assertUnprocessable()->assertJsonValidationErrors(['date' => 'There is already a holiday on this date. Edit it instead.']);
    }

    public function test_a_declared_special_event_may_use_the_next_and_previous_days(): void
    {
        $add = fn ($date, $type = 'SPECIAL_NON_WORKING', $name = 'APEC Summit') => $this->postJson('/api/holidays', ['date' => $date, 'name' => $name, 'type' => $type]);

        $add('2028-11-18')->assertCreated();
        $add('2028-11-19')->assertCreated();                  // the day after
        $add('2028-11-17')->assertCreated();                  // the day before
        $add('2028-11-16', 'SPECIAL_NON_WORKING', 'apec summit.')->assertCreated();   // written differently
        Holiday::where('date', '2028-11-16')->delete();
        $add('2028-11-21')->assertUnprocessable()             // not next to one
            ->assertJsonValidationErrors(['name' => '"APEC Summit" is already a holiday in 2028 (Nov 17 to Nov 19). Edit that one instead.']);
        $add('2028-11-20', 'REGULAR')->assertUnprocessable()->assertJsonValidationErrors('name');   // the new one is not Special
        $this->assertSame(3, Holiday::where('year', 2028)->count());
    }

    public function test_regular_or_special_working_days_with_the_same_name_stay_blocked(): void
    {
        $this->postJson('/api/holidays', ['date' => '2028-06-12', 'name' => 'Independence Day', 'type' => 'REGULAR'])->assertCreated();
        foreach (['REGULAR', 'SPECIAL_NON_WORKING', 'SPECIAL_WORKING'] as $i => $type) {
            $this->postJson('/api/holidays', ['date' => '2028-06-1'.(3 + $i), 'name' => 'Independence Day', 'type' => $type])->assertUnprocessable()->assertJsonValidationErrors('name');
        }
        $this->postJson('/api/holidays', ['date' => '2028-08-21', 'name' => 'Plant Day', 'type' => 'SPECIAL_WORKING'])->assertCreated();
        $this->postJson('/api/holidays', ['date' => '2028-08-22', 'name' => 'Plant Day', 'type' => 'SPECIAL_WORKING'])->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    public function test_holidays_check_lists_multi_day_holidays(): void
    {
        foreach (['2028-04-10', '2028-04-11', '2028-04-12'] as $d) {
            Holiday::create(['date' => $d, 'year' => 2028, 'name' => 'Holy Week Break', 'type' => 'REGULAR']);
        }
        $this->artisan('holidays:check')
            ->expectsOutputToContain('Multi-day holidays (consecutive days with one name')
            ->expectsOutputToContain('Holy Week Break')
            ->assertSuccessful();
        $this->assertSame(3, Holiday::where('year', 2028)->count());
    }
}
