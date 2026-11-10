<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// HR > Leave Requests > Leave Balances: the same credits as the employee's own
// page, with the used credits split by leave type.
class LeaveBalancesTest extends TestCase
{
    use RefreshDatabase;

    public function test_numbers_match_my_leave_credits_and_split_by_type(): void
    {
        $user = User::factory()->create(['role' => 'employee', 'sub_role' => 'checker']);
        $year = now(config('atice.timezone'))->year;
        $emp = Employee::create([
            'user_id' => $user->id, 'employee_id' => 'EMP-0401', 'first_name' => 'Analyn', 'last_name' => 'Reyes',
            'position' => 'Checker', 'daily_rate' => 650, 'date_hired' => ($year - 2).'-01-10',
            'employment_type' => 'regular', 'employment_status' => 'regular', 'regularized_at' => ($year - 1).'-07-10',
            'shift' => 'day', 'shift_start' => '07:00', 'shift_end' => '16:00', 'is_active' => true,
        ]);
        foreach ([['sick', 1], ['vacation', 2], ['sick', 0.5]] as $i => [$type, $paid]) {
            LeaveRequest::create([
                'employee_id' => $emp->id, 'leave_type' => $type, 'start_date' => "{$year}-01-0".($i + 2),
                'end_date' => "{$year}-01-0".($i + 2), 'total_days' => 1, 'paid_days' => $paid,
                'reason' => 'Family matter', 'status' => 'approved',
            ]);
        }
        LeaveRequest::create([
            'employee_id' => $emp->id, 'leave_type' => 'emergency', 'start_date' => "{$year}-01-08",
            'end_date' => "{$year}-01-08", 'total_days' => 1, 'paid_days' => 1, 'reason' => 'Flood', 'status' => 'pending',
        ]);

        Sanctum::actingAs($user);
        $own = $this->getJson('/api/my-leave-credits')->assertOk()->json();

        app('auth')->forgetGuards();
        Sanctum::actingAs(User::factory()->create(['role' => 'hr']));
        $row = collect($this->getJson('/api/leave-balances')->assertOk()->json('employees'))->firstWhere('employee_id', 'EMP-0401');

        $this->assertEquals($own['earned'], $row['earned']);
        $this->assertEquals($own['used'], $row['used']);
        $this->assertEquals($own['available'], $row['available']);
        $this->assertEquals(1.5, $row['used_by_type']['sick']);
        $this->assertEquals(2, $row['used_by_type']['vacation']);
        $this->assertEquals(0, $row['used_by_type']['emergency']); // pending leave does not count
    }

    public function test_non_hr_gets_403(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'employee', 'sub_role' => 'salesman']));
        $this->getJson('/api/leave-balances')->assertForbidden();

        app('auth')->forgetGuards();
        Sanctum::actingAs(User::factory()->create(['role' => 'customer']));
        $this->getJson('/api/leave-balances')->assertForbidden();
    }
}
