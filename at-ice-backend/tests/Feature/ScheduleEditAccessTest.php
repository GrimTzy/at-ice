<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Work schedules are HR's: Admin passes role:hr routes, but cannot change a shift.
class ScheduleEditAccessTest extends TestCase
{
    use RefreshDatabase;

    private function employee(): Employee
    {
        return Employee::create([
            'employee_id' => 'EMP-0301', 'first_name' => 'Jomar', 'last_name' => 'Villanueva',
            'position' => 'Guard', 'department' => 'Security', 'daily_rate' => 600, 'date_hired' => '2026-03-02',
            'employment_type' => 'regular', 'shift' => 'day', 'shift_start' => '07:00', 'shift_end' => '16:00',
            'is_active' => true,
        ]);
    }

    public function test_admin_cannot_change_a_work_schedule(): void
    {
        $emp = $this->employee();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->putJson("/api/employees/{$emp->id}", ['shift_start' => '21:00', 'shift_end' => '06:00'])->assertForbidden();

        $this->assertSame('07:00', substr($emp->fresh()->shift_start, 0, 5));
        $this->assertDatabaseCount('employee_shift_changes', 0);
    }

    public function test_admin_sending_the_same_hours_is_not_a_schedule_change(): void
    {
        $emp = $this->employee();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->putJson("/api/employees/{$emp->id}", ['shift_start' => '07:00', 'shift_end' => '16:00', 'department' => 'Operations'])->assertOk();
    }

    public function test_hr_can_change_a_work_schedule(): void
    {
        $emp = $this->employee();
        Sanctum::actingAs(User::factory()->create(['role' => 'hr']));

        $this->putJson("/api/employees/{$emp->id}", ['shift_start' => '21:00', 'shift_end' => '06:00'])->assertOk();
        $this->assertSame('21:00', substr($emp->fresh()->shift_start, 0, 5));
    }
}
