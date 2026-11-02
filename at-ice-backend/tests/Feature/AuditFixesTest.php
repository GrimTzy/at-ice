<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Payroll;
use App\Models\User;
use App\Support\Lateness;
use App\Support\PayrollCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Regression tests for the final system audit fixes (F-02, F-03, F-05).
class AuditFixesTest extends TestCase
{
    use RefreshDatabase;

    private const PERIOD = ['start' => '2026-11-23', 'end' => '2026-12-06', 'pay_day' => '2026-12-11'];

    private function payroll(): Payroll
    {
        $e = Employee::create([
            'employee_id' => 'EMP-0001', 'first_name' => 'Ana', 'last_name' => 'Reyes', 'position' => 'Guard', 'daily_rate' => 700,
            'pay_basis' => 'daily', 'date_hired' => '2026-01-05', 'employment_type' => 'regular', 'shift' => 'day',
            'shift_start' => '09:00', 'shift_end' => '18:00', 'is_active' => true, 'employment_status' => 'regular', 'regularized_at' => '2026-01-05',
        ]);

        return Payroll::create(['employee_id' => $e->id, 'period_start' => self::PERIOD['start'], 'period_end' => self::PERIOD['end'], 'pay_day' => self::PERIOD['pay_day'], 'status' => 'unpaid', 'prepared_by' => User::factory()->create(['role' => 'hr'])->id]
            + PayrollCalculator::compute($e, self::PERIOD));
    }

    // F-02
    public function test_a_paid_payroll_cannot_be_changed_and_marking_paid_twice_is_harmless(): void
    {
        $payroll = $this->payroll();
        Sanctum::actingAs(User::factory()->create(['role' => 'hr']));

        $adjustment = $this->postJson("/api/payrolls/{$payroll->id}/adjustments", ['label' => 'Bonus', 'amount' => 100, 'taxable' => true])
            ->assertCreated()->json('adjustments.0.id');

        $this->postJson('/api/payrolls/mark-paid', ['ids' => [$payroll->id]])->assertOk()->assertJsonPath('paid', 1);
        $paidAt = $payroll->fresh()->paid_at;
        $net = $payroll->fresh()->net_pay;

        $this->postJson('/api/payrolls/mark-paid', ['ids' => [$payroll->id]])->assertOk()->assertJsonPath('paid', 0);
        $this->postJson("/api/payrolls/{$payroll->id}/adjustments", ['label' => 'Late', 'amount' => 50, 'taxable' => true])->assertUnprocessable();
        $this->deleteJson("/api/payroll-adjustments/{$adjustment}")->assertUnprocessable();

        $fresh = $payroll->fresh();
        $this->assertSame('paid', $fresh->status);
        $this->assertEquals($paidAt, $fresh->paid_at);
        $this->assertEquals($net, $fresh->net_pay);
        $this->assertDatabaseHas('payroll_adjustments', ['id' => $adjustment]);
        $this->assertSame(1, $payroll->adjustments()->count());
    }

    public function test_an_adjustment_is_rolled_back_when_the_recompute_fails(): void
    {
        $payroll = $this->payroll();
        Sanctum::actingAs(User::factory()->create(['role' => 'hr']));
        // Break the recompute: the employee row disappears behind the payroll.
        Employee::query()->whereKey($payroll->employee_id)->delete();

        try {
            $this->withoutExceptionHandling()->postJson("/api/payrolls/{$payroll->id}/adjustments", ['label' => 'Bonus', 'amount' => 100, 'taxable' => true]);
        } catch (\Throwable) {
            // expected: the recompute needs the employee
        }

        $this->assertSame(0, $payroll->adjustments()->count(), 'adjustment must not be left behind without a recompute');
    }

    // F-03
    public function test_a_very_late_time_in_is_late_not_on_time(): void
    {
        $this->assertSame(780, Lateness::minutes('09:00', '22:00:00', 0));   // 13 hours late
        $this->assertSame(840, Lateness::minutes('09:00', '23:00:00', 0));
        $this->assertSame(0, Lateness::minutes('09:00', '06:30:00', 0));     // early
        $this->assertSame(0, Lateness::minutes('00:30', '23:55:00', 0));     // early for a just-after-midnight start
        $this->assertSame(150, Lateness::minutes('22:00', '00:30:00', 0));   // overnight still works
        $this->assertSame(10, Lateness::minutes('09:00', '09:10:59', 0));
    }

    // F-05
    public function test_login_answers_the_same_for_an_unknown_email_and_a_wrong_password(): void
    {
        User::factory()->create(['email' => 'known@example.com', 'password' => Hash::make('Correct#123')]);

        $unknown = $this->postJson('/api/login', ['email' => 'nobody@example.com', 'password' => 'Wrong#12345']);
        $wrong = $this->postJson('/api/login', ['email' => 'known@example.com', 'password' => 'Wrong#12345']);

        $unknown->assertStatus(401)->assertJsonPath('message', 'Invalid credentials.');
        $wrong->assertStatus(401)->assertJsonPath('message', 'Invalid credentials.');
        $this->assertSame($unknown->json('attempts_remaining'), $wrong->json('attempts_remaining'));

        $this->postJson('/api/login', ['email' => 'known@example.com', 'password' => 'Correct#123'])->assertOk()->assertJsonStructure(['token']);
    }
}
