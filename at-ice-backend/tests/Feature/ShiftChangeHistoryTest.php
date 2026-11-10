<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// HR > Employees: schedule changes keep the agreed schedule and a history.
class ShiftChangeHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function employee(): Employee
    {
        return Employee::create([
            'employee_id' => 'EMP-0101', 'first_name' => 'Rodel', 'last_name' => 'Santos',
            'position' => 'Guard', 'daily_rate' => 600, 'date_hired' => '2026-01-05',
            'employment_type' => 'regular', 'shift' => 'day', 'shift_start' => '07:00', 'shift_end' => '16:00',
            'is_active' => true,
        ]);
    }

    private function hr(): User
    {
        return User::factory()->create(['role' => 'hr']);
    }

    public function test_a_shift_change_writes_a_history_row_and_an_audit_entry(): void
    {
        $emp = $this->employee();
        Sanctum::actingAs($hr = $this->hr());

        $this->putJson("/api/employees/{$emp->id}", [
            'shift_start' => '08:00', 'shift_end' => '17:00',
            'shift_effective_date' => '2026-11-16', 'shift_reason' => 'Moved to the morning loading crew',
        ])->assertOk();

        $this->assertDatabaseHas('employee_shift_changes', [
            'employee_id' => $emp->id, 'from_start' => '07:00', 'from_end' => '16:00',
            'to_start' => '08:00', 'to_end' => '17:00', 'changed_by' => $hr->id,
            'reason' => 'Moved to the morning loading crew',
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'employee.shift_changed', 'user_id' => $hr->id, 'subject_id' => $emp->id]);
    }

    public function test_the_original_schedule_is_kept_after_two_changes(): void
    {
        $emp = $this->employee();
        Sanctum::actingAs($this->hr());

        $this->putJson("/api/employees/{$emp->id}", ['shift_start' => '08:00', 'shift_end' => '17:00'])->assertOk();
        $this->putJson("/api/employees/{$emp->id}", ['shift_start' => '21:00', 'shift_end' => '06:00'])->assertOk();

        $res = $this->getJson("/api/employees/{$emp->id}/shift-changes")->assertOk();
        $res->assertJsonPath('original.start', '07:00')
            ->assertJsonPath('original.end', '16:00')
            ->assertJsonPath('current.start', '21:00')
            ->assertJsonPath('current.end', '06:00')
            ->assertJsonCount(2, 'changes');
    }

    public function test_saving_the_same_hours_writes_no_history(): void
    {
        $emp = $this->employee();
        Sanctum::actingAs($this->hr());

        $this->putJson("/api/employees/{$emp->id}", ['shift_start' => '07:00', 'shift_end' => '16:00'])->assertOk();

        $this->assertDatabaseCount('employee_shift_changes', 0);
    }

    public function test_the_end_must_differ_from_the_start_and_the_date_must_be_valid(): void
    {
        $emp = $this->employee();
        Sanctum::actingAs($this->hr());

        $this->putJson("/api/employees/{$emp->id}", ['shift_start' => '08:00', 'shift_end' => '08:00'])
            ->assertStatus(422)->assertJsonValidationErrors('shift_end');
        $this->putJson("/api/employees/{$emp->id}", ['shift_start' => '08:00', 'shift_end' => '17:00', 'shift_effective_date' => 'next week'])
            ->assertStatus(422)->assertJsonValidationErrors('shift_effective_date');
        $this->assertDatabaseCount('employee_shift_changes', 0);
    }

    public function test_non_hr_cannot_change_a_schedule_or_read_the_history(): void
    {
        $emp = $this->employee();
        Sanctum::actingAs(User::factory()->create(['role' => 'employee', 'sub_role' => 'checker']));

        $this->putJson("/api/employees/{$emp->id}", ['shift_start' => '08:00', 'shift_end' => '17:00'])->assertForbidden();
        $this->getJson("/api/employees/{$emp->id}/shift-changes")->assertForbidden();
        $this->assertDatabaseCount('employee_shift_changes', 0);
    }
}
