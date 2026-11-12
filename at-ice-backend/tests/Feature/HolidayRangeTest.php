<?php

namespace Tests\Feature;

use App\Models\Holiday;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Add holiday with a date range (QA flag): one row per date, each date checked
// for an existing holiday, at most 31 days.
class HolidayRangeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create(['role' => 'hr']));
    }

    public function test_a_range_makes_one_holiday_per_date(): void
    {
        $this->postJson('/api/holidays', ['date' => '2028-04-10', 'end_date' => '2028-04-14', 'name' => 'Plant maintenance break', 'type' => 'SPECIAL_NON_WORKING'])
            ->assertCreated()
            ->assertJsonPath('message', 'Plant maintenance break was added for 5 days.')
            ->assertJsonCount(5, 'holidays');

        $this->assertSame(
            ['2028-04-10', '2028-04-11', '2028-04-12', '2028-04-13', '2028-04-14'],
            Holiday::where('name', 'Plant maintenance break')->orderBy('date')->pluck('date')->map(fn ($d) => substr((string) $d, 0, 10))->all()
        );
        $this->assertSame(2028, Holiday::forDate('2028-04-12')->year);
    }

    public function test_one_date_still_works_as_before(): void
    {
        $this->postJson('/api/holidays', ['date' => '2028-05-01', 'name' => 'Labor Day', 'type' => 'REGULAR'])
            ->assertCreated()->assertJsonPath('name', 'Labor Day');
        $this->postJson('/api/holidays', ['date' => '2028-05-02', 'end_date' => '2028-05-02', 'name' => 'Extra', 'type' => 'REGULAR'])
            ->assertCreated()->assertJsonPath('name', 'Extra');
    }

    public function test_a_range_with_a_date_already_taken_saves_nothing(): void
    {
        Holiday::create(['date' => '2028-04-12', 'year' => 2028, 'name' => 'Existing', 'type' => 'REGULAR']);

        $this->postJson('/api/holidays', ['date' => '2028-04-10', 'end_date' => '2028-04-14', 'name' => 'Break', 'type' => 'REGULAR'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date' => 'There is already a holiday on Apr 12, 2028. Edit it instead, or pick other dates.']);
        $this->assertSame(0, Holiday::where('name', 'Break')->count());

        // the start date taken: the usual one-date message
        $this->postJson('/api/holidays', ['date' => '2028-04-12', 'end_date' => '2028-04-13', 'name' => 'Break', 'type' => 'REGULAR'])
            ->assertUnprocessable()->assertJsonValidationErrors('date');
    }

    public function test_a_range_longer_than_31_days_or_backwards_is_refused(): void
    {
        $this->postJson('/api/holidays', ['date' => '2028-01-01', 'end_date' => '2028-02-01', 'name' => 'Too long', 'type' => 'REGULAR'])
            ->assertUnprocessable()->assertJsonValidationErrors(['end_date' => 'A holiday range can be at most 31 days (32 days chosen).']);
        $this->postJson('/api/holidays', ['date' => '2028-01-01', 'end_date' => '2028-01-31', 'name' => 'Exactly 31', 'type' => 'REGULAR'])
            ->assertCreated()->assertJsonCount(31, 'holidays');
        $this->postJson('/api/holidays', ['date' => '2028-03-10', 'end_date' => '2028-03-09', 'name' => 'Backwards', 'type' => 'REGULAR'])
            ->assertUnprocessable()->assertJsonValidationErrors('end_date');
        $this->assertSame(0, Holiday::whereIn('name', ['Too long', 'Backwards'])->count());
    }
}
