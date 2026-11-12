<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use App\Support\PayrollCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// HR can skip the SSS, PhilHealth and Pag-IBIG deduction for one employee. A reason is required.
class GovDeductionSkipTest extends TestCase
{
    use RefreshDatabase;

    private const PERIODS = [
        ['start' => '2026-11-23', 'end' => '2026-12-06', 'pay_day' => '2026-12-11'],
        ['start' => '2026-12-07', 'end' => '2026-12-20', 'pay_day' => '2026-12-25'],
    ];

    private function employee(array $extra = []): Employee
    {
        return Employee::create($extra + [
            'employee_id' => 'EMP-0001', 'first_name' => 'Ana', 'last_name' => 'Reyes', 'position' => 'Guard', 'daily_rate' => 700,
            'pay_basis' => 'daily', 'date_hired' => '2026-01-05', 'employment_type' => 'regular', 'shift' => 'day',
            'shift_start' => '09:00', 'shift_end' => '18:00', 'is_active' => true, 'employment_status' => 'regular', 'regularized_at' => '2026-01-05',
        ]);
    }

    private function government(Employee $e): float
    {
        return array_sum(array_map(function ($p) use ($e) {
            $v = PayrollCalculator::compute($e, $p);

            return (float) $v['sss_deduction'] + (float) $v['philhealth_deduction'] + (float) $v['pagibig_deduction'];
        }, self::PERIODS));
    }

    public function test_a_normal_employee_has_the_deductions_and_a_skipped_one_has_none(): void
    {
        $this->assertGreaterThan(0, $this->government($this->employee()));
        $this->assertSame(0.0, $this->government($this->employee(['employee_id' => 'EMP-0002', 'skip_gov_deductions' => true, 'gov_skip_reason' => 'Job order'])));
    }

    public function test_hr_must_give_a_reason_to_skip_and_the_reason_is_cleared_when_it_is_turned_off(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'hr']));
        $e = $this->employee();

        $this->putJson("/api/employees/{$e->id}", ['skip_gov_deductions' => true])->assertStatus(422)->assertJsonValidationErrors('gov_skip_reason');

        $this->putJson("/api/employees/{$e->id}", ['skip_gov_deductions' => true, 'gov_skip_reason' => 'Job order'])->assertOk();
        $this->assertTrue($e->fresh()->skip_gov_deductions);
        $this->assertSame('Job order', $e->fresh()->gov_skip_reason);

        $this->putJson("/api/employees/{$e->id}", ['skip_gov_deductions' => false, 'gov_skip_reason' => null])->assertOk();
        $this->assertFalse($e->fresh()->skip_gov_deductions);
    }
}
