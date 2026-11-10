<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Payroll;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// A pay period is a block of payroll.cutoff_days days counted from payroll.anchor.
// Changing either after payrolls exist re-slices every period, so a new period can
// overlap a payroll that was already made (for example a paid 12-25 Oct payroll
// plus cutoff 14 -> 7 gives an unpaid 19-25 Oct payroll, so those days would be
// paid twice). Two guards: the two settings are locked once any payroll
// exists, and an approval never creates a payroll that overlaps another payroll of
// the same employee (the second one also covers a change made outside the Admin
// page, such as the PAYROLL_ANCHOR environment value).
class PayrollPeriodGuardTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now('Asia/Manila')->setDate(2026, 11, 2)->setTime(9, 0));
        $this->employee = Employee::create([
            'employee_id' => 'EMP-0001', 'first_name' => 'Ana', 'last_name' => 'Reyes', 'position' => 'Guard', 'daily_rate' => 700,
            'date_hired' => '2026-01-05', 'employment_type' => 'regular', 'shift' => 'day', 'is_active' => true,
        ]);
    }

    private function asHr(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'hr', 'is_active' => true]));
    }

    private function approve(string $date)
    {
        $this->asHr();

        return $this->postJson('/api/timesheets/approve', ['date' => $date, 'employee_ids' => [$this->employee->id]]);
    }

    private function setting(string $key, mixed $value)
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'hr', 'is_active' => true]));

        return $this->putJson('/api/settings', ['key' => $key, 'value' => $value]);
    }

    private function payPeriod(): void
    {
        $this->approve('2026-10-14')->assertOk()->assertJsonPath('approved', 1);
    }

    // ---- guard 1: the two settings -------------------------------------------

    public function test_the_cutoff_and_the_anchor_cannot_change_once_a_payroll_exists(): void
    {
        $this->payPeriod();

        $this->setting('payroll.cutoff_days', 7)->assertStatus(422)->assertJsonValidationErrors('value');
        $this->setting('payroll.anchor', '2026-01-12')->assertStatus(422)->assertJsonValidationErrors('value');

        $this->assertSame(14, Settings::get('payroll.cutoff_days'));
        $this->assertSame('2026-01-05', Settings::get('payroll.anchor'));
    }

    public function test_the_refusal_says_why_and_what_to_do(): void
    {
        $this->payPeriod();

        $message = $this->setting('payroll.cutoff_days', 7)->json('errors.value.0');

        $this->assertStringContainsString('payroll', strtolower($message));
        $this->assertStringContainsString('already', strtolower($message));
    }

    public function test_they_can_change_while_no_payroll_exists_and_other_payroll_settings_stay_editable(): void
    {
        $this->setting('payroll.cutoff_days', 7)->assertOk();
        $this->assertSame(7, Settings::get('payroll.cutoff_days'));
        $this->setting('payroll.cutoff_days', 14)->assertOk();

        $this->payPeriod();
        $this->setting('payroll.payday_offset_days', 3)->assertOk(); // moves the pay day only, never a period
        $this->setting('payroll.hours_per_day', 9)->assertOk();
        $this->setting('payroll.cutoff_days', 14)->assertOk();       // the value it already has is not a change
    }

    // ---- guard 2: an approval never overlaps another payroll ------------------

    public function test_an_approval_that_would_overlap_a_paid_payroll_is_refused_and_writes_nothing(): void
    {
        $this->payPeriod();
        $paid = Payroll::sole();
        $paid->update(['status' => 'paid', 'paid_at' => now()]);
        Settings::set('payroll.cutoff_days', 7); // a change made outside the Admin page

        $this->approve('2026-10-20')->assertOk()
            ->assertJsonPath('approved', 0)
            ->assertJsonPath('overlapping', ['EMP-0001']);

        $this->assertSame(1, Payroll::count());
        $this->assertSame('paid', $paid->fresh()->status);
    }

    public function test_an_unpaid_payroll_with_a_different_start_also_blocks_an_overlapping_one(): void
    {
        $this->payPeriod(); // 12-25 Oct, unpaid
        Settings::set('payroll.cutoff_days', 7);

        $this->approve('2026-10-20')->assertOk()->assertJsonPath('approved', 0)->assertJsonPath('overlapping', ['EMP-0001']);

        $this->assertSame(1, Payroll::count());
        $this->assertSame('2026-10-25', Payroll::sole()->period_end->toDateString());
    }

    public function test_the_message_names_the_employee_and_the_conflicting_period(): void
    {
        $this->payPeriod();
        Settings::set('payroll.cutoff_days', 7);

        $message = $this->approve('2026-10-20')->json('message');

        $this->assertStringContainsString('EMP-0001', $message);
        $this->assertStringContainsString('2026-10-12', $message);
        $this->assertStringContainsString('2026-10-25', $message);
    }

    public function test_the_next_period_that_touches_but_does_not_overlap_is_still_approved(): void
    {
        $this->payPeriod(); // 12-25 Oct
        $this->travelTo(now('Asia/Manila')->setDate(2026, 11, 10)->setTime(9, 0));

        $this->approve('2026-10-27')->assertOk()->assertJsonPath('approved', 1)->assertJsonPath('overlapping', []);

        $this->assertSame(2, Payroll::count());
    }

    public function test_redoing_the_same_period_is_not_an_overlap(): void
    {
        $this->payPeriod();

        $this->approve('2026-10-14')->assertOk()->assertJsonPath('approved', 1)->assertJsonPath('overlapping', []);

        $this->assertSame(1, Payroll::count());
    }
}
