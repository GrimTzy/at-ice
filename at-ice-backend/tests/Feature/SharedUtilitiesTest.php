<?php

namespace Tests\Feature;

use App\Models\ContributionRate;
use App\Models\User;
use App\Support\Money;
use App\Support\PayPeriod;
use App\Support\Rates;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Review change request, phase 1: cutoff, money, rates, settings, audit log.
class SharedUtilitiesTest extends TestCase
{
    use RefreshDatabase;

    public function test_any_date_gives_the_14_day_cutoff_that_contains_it(): void
    {
        $this->assertSame(['start' => '2026-10-12', 'end' => '2026-10-25', 'pay_day' => '2026-10-30'], PayPeriod::for('2026-10-14'));
        $this->assertSame('2026-10-12', PayPeriod::for('2026-10-12')['start']); // first day
        $this->assertSame('2026-10-12', PayPeriod::for('2026-10-25')['start']); // last day
        $this->assertSame('2026-10-26', PayPeriod::for('2026-10-26')['start']); // next one
        $this->assertSame(['2026-08-17', '2026-08-30'], array_values(array_slice(PayPeriod::for('2026-08-20'), 0, 2)));
    }

    public function test_dates_before_the_anchor_and_across_a_year_work(): void
    {
        // 2025-12-22 to 2026-01-04 is the period just before the anchor.
        $this->assertSame(['2025-12-22', '2026-01-04'], array_values(array_slice(PayPeriod::for('2025-12-31'), 0, 2)));
        $this->assertSame(['2025-12-22', '2026-01-04'], array_values(array_slice(PayPeriod::for('2026-01-01'), 0, 2)));
        $this->assertSame(['2026-12-21', '2027-01-03'], array_values(array_slice(PayPeriod::for('2027-01-03'), 0, 2))); // crosses the year
        $this->assertSame('2025-12-22', PayPeriod::previous('2026-01-10')['start']);
    }

    public function test_a_month_can_have_three_pay_days(): void
    {
        // Pay days fall every 14 days from 2026-01-09: Jan 9, Jan 23, Feb 6, ...
        $this->assertSame(['2026-01-09', '2026-01-23'], PayPeriod::payDaysInMonth(2026, 1));
        $this->assertCount(3, PayPeriod::payDaysInMonth(2026, 10)); // Oct 2, 16, 30
        $this->assertSame(3, PayPeriod::payDayNumber('2026-10-30'));
        $this->assertSame(1, PayPeriod::payDayNumber('2026-10-02'));
    }

    public function test_monthly_and_daily_rates(): void
    {
        $this->assertSame(732.94, Rates::dailyFromMonthly(16000));
        $this->assertSame(91.62, Rates::hourly(732.94));
        $this->assertEqualsWithDelta(1.527, Rates::perMinute(732.94), 0.0001);
        $this->assertSame(732.94, Rates::dailyFor('monthly', null, 16000));
        $this->assertSame(600.0, Rates::dailyFor('daily', 600, null));
    }

    public function test_money_rounds_only_at_the_end_half_up(): void
    {
        $this->assertSame(1.01, Money::round(1.005));
        $this->assertSame(2382.12, Money::round(26 * 91.62));
        $this->assertSame('7,801.49', Money::format((96000 - 2382.12) / 12));
    }

    public function test_settings_have_defaults_hr_can_change_them_and_it_is_audited(): void
    {
        $this->assertSame('SECOND_PAYROLL_OF_MONTH', Settings::get('payroll.gov_deduction_strategy'));
        $this->assertSame(0, Settings::get('attendance.grace_minutes'));

        $hr = User::factory()->create(['role' => 'hr']);
        Sanctum::actingAs($hr);
        $this->putJson('/api/settings', ['key' => 'attendance.grace_minutes', 'value' => '5'])->assertOk()->assertJsonPath('value', 5);
        $this->assertSame(5, Settings::get('attendance.grace_minutes'));

        $log = DB::table('audit_logs')->where('action', 'setting.changed')->first();
        $this->assertSame($hr->id, $log->user_id);
        $this->assertSame(['key' => 'attendance.grace_minutes', 'value' => 0], json_decode($log->before, true));

        $this->putJson('/api/settings', ['key' => 'payroll.gov_deduction_strategy', 'value' => 'MONTHLY'])->assertStatus(422);
        $this->putJson('/api/settings', ['key' => 'attendance.shift_start', 'value' => '25:00'])->assertStatus(422);

        // Rates and rules moved from Admin to HR (owner, 2026-11-09)
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->putJson('/api/settings', ['key' => 'attendance.grace_minutes', 'value' => 9])->assertForbidden();
        $this->getJson('/api/settings')->assertForbidden();
    }

    public function test_the_kiosk_unlock_and_record_expiry_settings_stay_with_admin(): void
    {
        // HR does not see or change them
        Sanctum::actingAs(User::factory()->create(['role' => 'hr']));
        $keys = collect($this->getJson('/api/settings')->assertOk()->json())->pluck('key');
        $this->assertNotContains('kiosk.hr_can_override', $keys);
        $this->assertNotContains('archive.expiry_action', $keys);
        $this->assertContains('attendance.grace_minutes', $keys);
        $this->putJson('/api/settings', ['key' => 'kiosk.hr_can_override', 'value' => true])->assertForbidden();
        $this->putJson('/api/settings', ['key' => 'archive.expiry_action', 'value' => 'PURGE'])->assertForbidden();
        $this->getJson('/api/admin-settings')->assertForbidden();
        $this->assertFalse(Settings::get('kiosk.hr_can_override'));

        // Admin changes only these two
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->assertSame(['kiosk.hr_can_override', 'archive.expiry_action'], collect($this->getJson('/api/admin-settings')->assertOk()->json())->pluck('key')->all());
        $this->putJson('/api/admin-settings', ['key' => 'kiosk.hr_can_override', 'value' => true])->assertOk();
        $this->assertTrue(Settings::get('kiosk.hr_can_override'));
        $this->putJson('/api/admin-settings', ['key' => 'attendance.grace_minutes', 'value' => 3])->assertForbidden();
    }

    public function test_each_rates_and_rules_page_loads_and_saves_only_its_own_settings(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'hr']));

        $keys = collect($this->getJson('/api/settings/attendance')->assertOk()->json())->pluck('key');
        $this->assertContains('attendance.grace_minutes', $keys);
        $this->assertNotContains('payroll.anchor', $keys);
        $this->putJson('/api/settings/attendance', ['key' => 'attendance.grace_minutes', 'value' => 4])->assertOk();
        $this->putJson('/api/settings/attendance', ['key' => 'payroll.cutoff_days', 'value' => 7])->assertUnprocessable();

        // Records: the expiry action stays with Admin
        $this->assertNotContains('archive.expiry_action', collect($this->getJson('/api/settings/records')->json())->pluck('key'));
        $this->getJson('/api/settings/nope')->assertNotFound();

        // Deliveries and Support tickets are Admin's (owner, 2026-11-10)
        $this->getJson('/api/settings/deliveries')->assertNotFound();
        $this->getJson('/api/settings/support')->assertNotFound();
        $this->putJson('/api/settings', ['key' => 'delivery.max_transit_hours_before_alert', 'value' => 6])->assertForbidden();
        $this->assertNotContains('support.overdue_hours', collect($this->getJson('/api/settings')->json())->pluck('key'));

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->getJson('/api/settings/attendance')->assertForbidden();
        $keys = collect($this->getJson('/api/admin-settings/deliveries')->assertOk()->json())->pluck('key');
        $this->assertContains('delivery.allow_rolling_stock_fifo', $keys);
        $this->assertContains('delivery.auto_queue_on_trip_completion', $keys);
        $this->assertContains('plant.latitude', $keys);
        $this->assertContains('support.overdue_hours', collect($this->getJson('/api/admin-settings/support')->assertOk()->json())->pluck('key'));
        $this->putJson('/api/admin-settings/deliveries', ['key' => 'delivery.max_transit_hours_before_alert', 'value' => 6])->assertOk();
        $this->assertSame(6, Settings::get('delivery.max_transit_hours_before_alert'));
        $this->getJson('/api/admin-settings/payroll')->assertNotFound();
    }

    public function test_the_cutoff_endpoint_and_seeded_contribution_rules(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'hr']));
        $this->getJson('/api/pay-period?date=2026-10-14')->assertOk()->assertJsonPath('start', '2026-10-12')->assertJsonPath('end', '2026-10-25');

        $this->assertSame(0.05, ContributionRate::rulesOn('sss', '2026-10-30')['employee_rate']);
        $this->assertSame(200, ContributionRate::rulesOn('pagibig', '2026-10-30')['max']);
        $this->assertNull(ContributionRate::rulesOn('sss', '2020-01-01'));
    }
}
