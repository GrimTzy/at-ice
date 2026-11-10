<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\PayMultiplier;
use App\Models\User;
use App\Support\PayrollCalculator;
use App\Support\PayPeriod;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Night differential (Labor Code Art. 86): at least 10% of the hourly rate of
// each hour worked from 10:00 PM to 6:00 AM, on the rate of that hour
// (ordinary, overtime, holiday, holiday overtime).
class NightDifferentialTest extends TestCase
{
    use RefreshDatabase;

    private const DAY = '2026-11-03'; // a Tuesday (work day)

    private function guard(string $start, string $end): Employee
    {
        // 800 a day / 8 hours = 100 an hour
        return Employee::create([
            'employee_id' => 'EMP-0901', 'first_name' => 'Rowel', 'last_name' => 'Pascual', 'position' => 'Guard',
            'daily_rate' => 800, 'pay_basis' => 'daily', 'date_hired' => '2026-01-05', 'employment_type' => 'regular',
            'shift' => $end <= $start ? 'night' : 'day', 'shift_start' => $start, 'shift_end' => $end, 'is_active' => true,
        ]);
    }

    private function worked(Employee $e, string $in, string $out, float $hours, int $ot = 0, bool $approved = false, string $date = self::DAY): void
    {
        Attendance::create([
            'employee_id' => $e->id, 'date' => $date, 'time_in' => $in, 'time_out' => $out,
            'hours_worked' => $hours, 'ot_minutes' => $ot, 'ot_approved' => $approved, 'status' => 'present',
        ]);
    }

    private function pay(Employee $e, string $date = self::DAY): array
    {
        return PayrollCalculator::compute($e, PayPeriod::for($date));
    }

    public function test_a_full_night_shift_earns_ten_percent_on_eight_night_hours(): void
    {
        $e = $this->guard('21:00', '06:00');
        $this->worked($e, '21:00:00', '06:00:00', 9); // 22:00-06:00 = 8 night hours, 1 hour break

        $p = $this->pay($e);

        $this->assertEquals(8, $p['night_hours']);
        $this->assertEquals(80.00, $p['night_diff_pay']); // 8 x 100 x 10%
    }

    public function test_a_day_shift_earns_none(): void
    {
        $e = $this->guard('08:00', '17:00');
        $this->worked($e, '08:00:00', '17:00:00', 9);

        $p = $this->pay($e);

        $this->assertEquals(0, $p['night_hours']);
        $this->assertEquals(0, $p['night_diff_pay']);
    }

    public function test_only_the_hours_inside_ten_to_six_count(): void
    {
        $e = $this->guard('18:00', '03:00');
        $this->worked($e, '18:00:00', '03:00:00', 9); // 22:00-03:00 = 5 night hours

        $this->assertEquals(50.00, $this->pay($e)['night_diff_pay']);
    }

    public function test_approved_overtime_at_night_is_on_the_overtime_rate(): void
    {
        $e = $this->guard('13:00', '22:00');
        $this->worked($e, '13:00:00', '00:00:00', 11, 120, true); // OT 22:00-00:00

        $ot = PayMultiplier::rate('OT_ORDINARY', self::DAY);
        $p = $this->pay($e);

        $this->assertEquals(2, $p['night_hours']);
        $this->assertEqualsWithDelta(round(2 * 100 * $ot * 0.10, 2), $p['night_diff_pay'], 0.01);
    }

    public function test_overtime_not_yet_approved_earns_no_night_differential(): void
    {
        $e = $this->guard('13:00', '22:00');
        $this->worked($e, '13:00:00', '00:00:00', 11, 120, false);

        $this->assertEquals(0, $this->pay($e)['night_diff_pay']);
    }

    public function test_a_night_on_a_regular_holiday_is_on_the_holiday_rate(): void
    {
        Holiday::create(['date' => self::DAY, 'name' => 'Test Holiday', 'type' => 'REGULAR', 'is_double' => false, 'year' => 2026]);
        $e = $this->guard('21:00', '06:00');
        $this->worked($e, '21:00:00', '06:00:00', 9);

        $rate = PayMultiplier::rate('REGULAR_HOLIDAY_WORKED', self::DAY);
        $p = $this->pay($e);

        $this->assertEqualsWithDelta(round(8 * 100 * $rate * 0.10, 2), $p['night_diff_pay'], 0.01);
    }

    public function test_the_night_differential_is_part_of_gross_pay(): void
    {
        $e = $this->guard('21:00', '06:00');
        $this->worked($e, '21:00:00', '06:00:00', 9);
        $with = $this->pay($e);

        Settings::set('payroll.night_diff_rate', 0.20);
        $higher = $this->pay($e);

        $this->assertEquals(160.00, $higher['night_diff_pay']);
        $this->assertEqualsWithDelta($with['gross_pay'] + 80, $higher['gross_pay'], 0.01);
    }

    public function test_the_rate_cannot_be_set_below_the_legal_ten_percent(): void
    {
        $this->assertNotNull(Settings::validate('payroll.night_diff_rate', 0.05));
        $this->assertNull(Settings::validate('payroll.night_diff_rate', 0.10));

        Sanctum::actingAs(User::factory()->create(['role' => 'hr']));
        $this->putJson('/api/settings', ['key' => 'payroll.night_diff_rate', 'value' => 0.05])
            ->assertStatus(422)->assertJsonPath('errors.value.0', 'The night differential is at least 0.10 (10%) by law, and at most 1.');
        $this->assertEquals(0.10, Settings::get('payroll.night_diff_rate'));

        $this->putJson('/api/settings', ['key' => 'payroll.night_diff_rate', 'value' => 0.15])->assertOk();
        $this->assertEquals(0.15, Settings::get('payroll.night_diff_rate'));
    }

    public function test_night_minutes_cross_midnight(): void
    {
        $tz = config('atice.timezone');
        $from = CarbonImmutable::parse('2026-11-03 20:30', $tz);

        $this->assertSame(480, PayrollCalculator::nightMinutes($from, $from->addHours(12)));      // 20:30-08:30
        $this->assertSame(30, PayrollCalculator::nightMinutes($from, $from->addHours(2)));        // 20:30-22:30
        $this->assertSame(0, PayrollCalculator::nightMinutes($from->setTime(6, 0), $from->setTime(22, 0)));
    }
}
