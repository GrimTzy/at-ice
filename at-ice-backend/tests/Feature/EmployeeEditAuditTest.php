<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Recent Changes: who edited an employee, what changed, never private values.
class EmployeeEditAuditTest extends TestCase
{
    use RefreshDatabase;

    private function employee(): Employee
    {
        return Employee::create([
            'employee_id' => 'EMP-0102', 'first_name' => 'Marites', 'last_name' => 'Gonzales',
            'position' => 'Bagger', 'department' => 'Production', 'daily_rate' => 610, 'date_hired' => '2026-02-02',
            'employment_type' => 'regular', 'shift' => 'day', 'shift_start' => '07:00', 'shift_end' => '16:00',
            'sss_no' => '34-1234567-8', 'is_active' => true,
        ]);
    }

    public function test_an_hr_edit_is_logged_with_the_right_user_and_only_the_changed_fields(): void
    {
        $emp = $this->employee();
        Sanctum::actingAs($hr = User::factory()->create(['role' => 'hr', 'name' => 'Lorna Bautista']));

        $this->putJson("/api/employees/{$emp->id}", ['department' => 'Operations', 'daily_rate' => 650, 'sss_no' => '34-7654321-0'])->assertOk();

        $log = DB::table('audit_logs')->where('action', 'employee.updated')->first();
        $this->assertNotNull($log);
        $this->assertSame($hr->id, (int) $log->user_id);
        $before = json_decode($log->before, true);
        $after = json_decode($log->after, true);
        $this->assertSame(['department' => 'Production'], $before);
        $this->assertSame('Operations', $after['department']);
        $this->assertEqualsCanonicalizing(['daily_rate', 'sss_no'], $after['private_fields_changed']);
        // the values of private fields are never stored
        $this->assertStringNotContainsString('650', $log->after);
        $this->assertStringNotContainsString('7654321', $log->after.$log->before);
    }

    public function test_the_audit_list_shows_who_in_plain_words_and_the_subject(): void
    {
        $emp = $this->employee();
        Sanctum::actingAs(User::factory()->create(['role' => 'hr', 'name' => 'Lorna Bautista']));
        $this->putJson("/api/employees/{$emp->id}", ['department' => 'Operations'])->assertOk();

        app('auth')->forgetGuards();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->getJson('/api/audit-logs')->assertOk()
            ->assertJsonFragment([
                'user_name' => 'Lorna Bautista',
                'user_role' => 'HR',
                'action_label' => 'Edited employee details',
                'subject_label' => 'Marites Gonzales (EMP-0102)',
            ]);
    }

    public function test_saving_without_changes_logs_nothing(): void
    {
        $emp = $this->employee();
        Sanctum::actingAs(User::factory()->create(['role' => 'hr']));

        $this->putJson("/api/employees/{$emp->id}", ['department' => 'Production'])->assertOk();

        $this->assertSame(0, DB::table('audit_logs')->where('action', 'employee.updated')->count());
    }

    public function test_hr_cannot_read_the_audit_list(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'hr']));

        $this->getJson('/api/audit-logs')->assertForbidden();
    }
}
