<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\PerformanceReview;
use App\Models\User;
use App\Support\PayPeriod;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// The read-only performance history of one employee: published reviews only,
// rating averaged per calendar quarter, ratings frozen in a snapshot when
// published, older reviews recalculated and flagged, Admin only.
class PerformanceHistoryTest extends TestCase
{
    use RefreshDatabase;

    private Employee $guard;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-16 17:00:00', 'Asia/Manila')); // a Friday
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@atice.cloud', 'password' => 'secret-password', 'role' => 'admin']);
        $this->guard = Employee::create(['employee_id' => 'EMP-0101', 'first_name' => 'Gina', 'last_name' => 'Guard', 'position' => 'Guard', 'daily_rate' => 600, 'date_hired' => '2026-01-05', 'is_active' => true]);
        $period = PayPeriod::for('2026-10-16');
        foreach (\Carbon\CarbonPeriod::create($period['start'], '2026-10-16') as $d) {
            if ($d->isWeekday()) {
                Attendance::create(['employee_id' => $this->guard->id, 'date' => $d->toDateString(), 'time_in' => '08:55:00', 'status' => 'present', 'late_minutes' => 0]);
            }
        }
        Sanctum::actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function review(string $start, string $end, array $extra = []): PerformanceReview
    {
        return PerformanceReview::create($extra + [
            'employee_id' => $this->guard->id, 'period_start' => $start, 'period_end' => $end,
            'status' => 'published', 'published_by' => $this->admin->id, 'published_at' => '2026-08-01 09:00:00',
        ]);
    }

    private function snapshotReview(string $start, string $end, float $rating, ?string $remarks = null): PerformanceReview
    {
        return $this->review($start, $end, [
            'published_rating' => $rating, 'remarks' => $remarks, 'snapshot_at' => '2026-08-01 09:00:00',
            'snapshot' => ['role' => null, 'work_days' => 10, 'present_days' => 9, 'on_time_days' => 9, 'attendance_rate' => 90.0, 'punctuality_rate' => 100.0,
                'goal_target' => null, 'goal_done' => null, 'goal_completion' => null, 'score' => $rating * 20, 'base_rating' => $rating,
                'final_rating' => null, 'rating' => $rating, 'rating_label' => 'Satisfactory'],
        ]);
    }

    private function history(string $query = '')
    {
        return $this->getJson("/api/performance/{$this->guard->id}/history".$query);
    }

    public function test_publishing_stores_a_snapshot_that_later_changes_do_not_touch(): void
    {
        $period = PayPeriod::for('2026-10-16');
        $this->putJson("/api/performance/{$this->guard->id}?period_start={$period['start']}", ['remarks' => 'Steady.', 'publish' => true])->assertOk();

        $review = PerformanceReview::firstOrFail();
        $this->assertNotNull($review->published_rating);
        $this->assertNull($review->final_rating); // no manual override: the rating is the calculated one
        $this->assertEquals($review->published_rating, $review->snapshot['rating']);
        $this->assertSame(100.0, (float) $review->snapshot['attendance_rate']);
        $this->assertNotNull($review->snapshot_at);

        $before = $this->history()->assertOk()->json('latest');
        $this->assertSame('snapshot', $before['source']);

        // The records disappear later: the published rating stays as it was
        Attendance::query()->delete();
        $after = $this->history()->assertOk()->json('latest');
        $this->assertSame($before['rating'], $after['rating']);
        $this->assertSame($before['attendance_rate'], $after['attendance_rate']);
    }

    public function test_a_manual_override_is_the_published_rating_and_editing_refreshes_the_snapshot(): void
    {
        $period = PayPeriod::for('2026-10-16');
        $url = "/api/performance/{$this->guard->id}?period_start={$period['start']}";
        $this->putJson($url, ['final_rating' => 3.2, 'publish' => true])->assertOk();
        $this->assertSame(3.2, PerformanceReview::first()->published_rating);
        $this->assertTrue($this->history()->json('latest.manual'));

        $this->putJson($url, ['final_rating' => 4.1, 'remarks' => 'Improved.'])->assertOk(); // still published, edited
        $review = PerformanceReview::first();
        $this->assertSame('published', $review->status);
        $this->assertSame(4.1, $review->published_rating);
        $this->assertSame(4.1, (float) $review->snapshot['rating']);
        $this->assertSame(2, \DB::table('audit_logs')->whereIn('action', ['performance.published', 'performance.updated'])->count());
    }

    public function test_a_draft_is_not_part_of_the_history(): void
    {
        $period = PayPeriod::for('2026-10-16');
        $this->putJson("/api/performance/{$this->guard->id}?period_start={$period['start']}", ['final_rating' => 2.0])->assertOk(); // saved, not published
        $this->assertNull(PerformanceReview::first()->snapshot);

        $json = $this->history()->assertOk()->json();
        $this->assertNull($json['latest']);
        $this->assertSame(0, $json['counts']['published']);
        $this->assertSame([], $json['feedback']);
        $this->assertNull(collect($json['trend'])->firstWhere('key', '2026-Q4')['average']);
    }

    public function test_quarters_average_their_published_cutoff_ratings(): void
    {
        $this->snapshotReview('2026-07-01', '2026-07-15', 4.0, 'Strong start.');
        $this->snapshotReview('2026-07-16', '2026-07-31', 3.0);
        $this->snapshotReview('2026-04-16', '2026-04-30', 2.5);

        $trend = collect($this->history('?from=2026-01-01&to=2026-10-16')->assertOk()->json('trend'));
        $this->assertSame(['2026-Q1', '2026-Q2', '2026-Q3', '2026-Q4'], $trend->pluck('key')->all());
        $q3 = $trend->firstWhere('key', '2026-Q3');
        $this->assertSame(3.5, (float) $q3['average']);
        $this->assertSame(2, $q3['count']);
        $this->assertCount(2, $q3['ratings']);
        $this->assertSame(2.5, (float) $trend->firstWhere('key', '2026-Q2')['average']);
        $this->assertNull($trend->firstWhere('key', '2026-Q1')['average']); // no published rating: missing, not zero
        $this->assertNull($trend->firstWhere('key', '2026-Q4')['average']);
    }

    public function test_a_cutoff_belongs_to_the_quarter_of_its_period_end(): void
    {
        $this->snapshotReview('2026-06-16', '2026-07-01', 4.0); // starts in Q2, ends in Q3

        $trend = collect($this->history('?from=2026-04-01&to=2026-09-30')->json('trend'));
        $this->assertNull($trend->firstWhere('key', '2026-Q2')['average']);
        $this->assertSame(4.0, (float) $trend->firstWhere('key', '2026-Q3')['average']);
    }

    public function test_header_shows_the_latest_published_rating_and_feedback_lists_published_remarks(): void
    {
        $this->snapshotReview('2026-07-01', '2026-07-15', 4.0, 'Strong start.');
        $this->snapshotReview('2026-08-01', '2026-08-15', 3.0, 'Needs more focus.');
        $this->review('2026-09-01', '2026-09-15', ['status' => 'draft', 'remarks' => 'Draft note.']);

        $json = $this->history()->assertOk()->json();
        $this->assertSame(3.0, (float) $json['latest']['rating']);
        $this->assertSame('2026-08-15', $json['latest']['period_end']);
        $this->assertSame(['Needs more focus.', 'Strong start.'], array_column($json['feedback'], 'remarks')); // newest first, no draft
        $this->assertSame('Admin', $json['feedback'][0]['reviewer']['name']);
        $this->assertSame('admin', $json['feedback'][0]['reviewer']['role']);
    }

    public function test_a_review_published_before_snapshots_is_recalculated_and_flagged(): void
    {
        $this->review('2026-10-01', '2026-10-15'); // published, no snapshot

        $json = $this->history()->assertOk()->json();
        $this->assertSame('recalculated', $json['latest']['source']);
        $this->assertSame(['published' => 1, 'snapshot' => 0, 'recalculated' => 1], $json['counts']);
        $this->assertNotNull($json['latest']['rating']);
    }

    public function test_attendance_follows_the_performance_rules_per_quarter(): void
    {
        $q = collect($this->history('?from=2026-10-01&to=2026-12-31')->assertOk()->json('attendance'))->firstWhere('key', '2026-Q4');
        $this->assertSame('2026-10-01', $q['covers']['from']);
        $this->assertSame('2026-10-16', $q['covers']['to']); // never past today
        $this->assertGreaterThan(0, $q['present_days']);
        $this->assertSame($q['work_days'] - $q['present_days'], $q['absent_days']);
        $this->assertSame(round(min(1, $q['present_days'] / $q['work_days']) * 100, 1), (float) $q['attendance_rate']);

        // Before the hire date nothing is expected
        $first = collect($this->history('?from=2025-10-01&to=2025-12-31')->json('attendance'))->firstWhere('key', '2025-Q4');
        $this->assertSame(0, $first['work_days']);
        $this->assertNull($first['attendance_rate']);
    }

    public function test_goals_show_the_role_work_only_for_roles_that_have_a_goal(): void
    {
        $this->assertFalse($this->history()->json('goals.applicable')); // a Guard has no work goal

        $user = User::create(['name' => 'Driver Dan', 'email' => 'dan@atice.cloud', 'password' => 'secret-password', 'role' => 'driver']);
        $driver = Employee::create(['employee_id' => 'EMP-0102', 'user_id' => $user->id, 'first_name' => 'Dan', 'last_name' => 'Driver', 'position' => 'Driver', 'daily_rate' => 700, 'date_hired' => '2026-01-05', 'is_active' => true]);
        PerformanceReview::create([
            'employee_id' => $driver->id, 'period_start' => '2026-07-01', 'period_end' => '2026-07-15', 'status' => 'published', 'published_at' => now(),
            'published_by' => $this->admin->id, 'published_rating' => 3.0, 'snapshot_at' => now(),
            'snapshot' => ['role' => 'driver', 'work_days' => 10, 'present_days' => 10, 'on_time_days' => 10, 'attendance_rate' => 100.0, 'punctuality_rate' => 100.0,
                'goal_target' => 20, 'goal_done' => 25, 'goal_completion' => 100.0, 'score' => 60, 'base_rating' => 3.0, 'final_rating' => null, 'rating' => 3.0, 'rating_label' => 'Satisfactory'],
        ]);
        PerformanceReview::create([
            'employee_id' => $driver->id, 'period_start' => '2026-07-16', 'period_end' => '2026-07-31', 'status' => 'published', 'published_at' => now(),
            'published_by' => $this->admin->id, 'published_rating' => 3.0, 'snapshot_at' => now(),
            'snapshot' => ['role' => 'driver', 'work_days' => 10, 'present_days' => 10, 'on_time_days' => 10, 'attendance_rate' => 100.0, 'punctuality_rate' => 100.0,
                'goal_target' => 20, 'goal_done' => 10, 'goal_completion' => 50.0, 'score' => 60, 'base_rating' => 3.0, 'final_rating' => null, 'rating' => 3.0, 'rating_label' => 'Satisfactory'],
        ]);

        $json = $this->getJson("/api/performance/{$driver->id}/history?from=2026-07-01&to=2026-09-30")->assertOk()->json();
        $this->assertTrue($json['goals']['applicable']);
        $this->assertSame('deliveries completed', $json['goals']['unit']);
        $q3 = collect($json['goals']['quarters'])->firstWhere('key', '2026-Q3');
        $this->assertSame(40, $q3['target']);
        $this->assertSame(35, $q3['done']);
        $this->assertSame(30, $q3['counted']);
        $this->assertSame(75.0, (float) $q3['completion']); // (20 capped + 10) of 40, an over-achieved cutoff does not cover a short one
    }

    public function test_by_month_groups_every_section_by_month(): void
    {
        $this->snapshotReview('2026-07-01', '2026-07-15', 4.0, 'July start.');
        $this->snapshotReview('2026-07-16', '2026-07-31', 3.0);
        $this->snapshotReview('2026-08-01', '2026-08-15', 2.0);

        $json = $this->history('?by=month&from=2026-01-01&to=2026-10-16')->assertOk()->json();
        $this->assertSame('month', $json['by']);
        $keys = ['2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06', '2026-07', '2026-08', '2026-09', '2026-10'];
        $this->assertSame($keys, array_column($json['trend'], 'key'));
        $this->assertSame($keys, array_column($json['attendance'], 'key')); // every section uses the same buckets
        $this->assertSame($keys, array_column($json['goals']['quarters'], 'key'));
        $trend = collect($json['trend']);
        $this->assertSame(3.5, (float) $trend->firstWhere('key', '2026-07')['average']);
        $this->assertSame(2, $trend->firstWhere('key', '2026-07')['count']);
        $this->assertSame(2.0, (float) $trend->firstWhere('key', '2026-08')['average']);
        $this->assertNull($trend->firstWhere('key', '2026-09')['average']);
        $this->assertSame('Oct 2026', collect($json['attendance'])->firstWhere('key', '2026-10')['label']);
        $this->assertGreaterThan(0, collect($json['attendance'])->firstWhere('key', '2026-10')['present_days']);

        // Month with no range given: this year so far
        $this->assertSame('2026-01-01', $this->history('?by=month')->json('range.from'));
        $this->history('?by=week')->assertStatus(422);
    }

    public function test_records_list_the_live_work_of_the_role(): void
    {
        $user = User::create(['name' => 'Driver Dan', 'email' => 'dan@atice.cloud', 'password' => 'secret-password', 'role' => 'driver']);
        $driver = Employee::create(['employee_id' => 'EMP-0102', 'user_id' => $user->id, 'first_name' => 'Dan', 'last_name' => 'Driver', 'position' => 'Driver', 'daily_rate' => 700, 'date_hired' => '2026-01-05', 'is_active' => true]);
        $customer = \App\Models\Customer::create(['name' => 'Alfamart', 'customer_code' => 'C-1', 'branch_code' => 'B-1', 'invoice_code' => 'ALF', 'is_active' => true]);
        $mk = fn ($no, $status, $at) => \App\Models\Delivery::create(['delivery_no' => $no, 'driver_id' => $user->id, 'customer_id' => $customer->id, 'status' => $status, 'scheduled_date' => substr($at, 0, 10), 'delivered_at' => $status === 'delivered' ? $at : null, 'prepared_by' => $user->id]);
        $mk('DEL-1', 'delivered', '2026-07-10 10:00:00');
        $mk('DEL-2', 'delivered', '2026-08-02 09:30:00');
        $mk('DEL-3', 'delivered', '2025-01-02 09:30:00'); // outside the range
        $mk('DEL-4', 'pending', '2026-08-03 08:00:00');   // not delivered

        $rec = $this->getJson("/api/performance/{$driver->id}/history?by=month&from=2026-07-01&to=2026-09-30")->assertOk()->json('records');
        $this->assertSame(['DEL-2', 'DEL-1'], array_column($rec['items'], 'ref')); // newest first, only delivered, only in range
        $this->assertSame('2026-08', $rec['items'][0]['bucket']);
        $this->assertSame('Alfamart', $rec['items'][0]['detail']);
        $this->assertFalse($rec['truncated']);

        $this->assertSame([], $this->history()->json('records.items')); // a Guard has no work records
    }

    public function test_only_admin_reads_the_history_and_the_range_is_validated(): void
    {
        $this->history('?from=2026-01-01&to=2026-06-30')->assertOk();
        $this->history('?from=2026-06-30&to=2026-01-01')->assertStatus(422);
        $this->history('?from=2019-01-01&to=2026-01-01')->assertStatus(422); // longer than 5 years
        $this->history('?from=not-a-date')->assertStatus(422);

        $this->guard->forceFill(['anonymized_at' => now()])->save();
        $this->history()->assertNotFound();
        $this->guard->forceFill(['anonymized_at' => null])->save();

        Sanctum::actingAs(User::create(['name' => 'HR', 'email' => 'hr@atice.cloud', 'password' => 'secret-password', 'role' => 'hr']));
        $this->history()->assertForbidden();
        $this->getJson("/api/performance/{$this->guard->id}/photo")->assertForbidden();
    }
}
