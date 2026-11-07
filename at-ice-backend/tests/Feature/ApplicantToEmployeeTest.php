<?php

namespace Tests\Feature;

use App\Models\Applicant;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// HR > Interviews > Add as Employee: only a hired applicant becomes an
// employee, only once, and the applicant is linked to that employee.
class ApplicantToEmployeeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::create(['name' => 'HR', 'email' => 'hr@atice.cloud', 'password' => 'secret-password', 'role' => 'hr']));
    }

    private function applicant(string $status): Applicant
    {
        return Applicant::create(['name' => 'Maria Reyes', 'phone' => '+639171234567', 'position_applied' => 'Salesman', 'status' => $status]);
    }

    private function hire(Applicant $a, string $last = 'Reyes', string $phone = '09171234567')
    {
        return $this->postJson('/api/employees', [
            'applicant_id' => $a->id, 'first_name' => 'Maria', 'last_name' => $last, 'phone' => $phone, 'position' => 'Salesman',
            'street' => 'Rizal St', 'barangay' => 'Muzon', 'city' => 'San Jose del Monte', 'province' => 'Bulacan',
            'daily_rate' => 695, 'date_hired' => now()->toDateString(), 'shift_start' => '08:00', 'shift_end' => '17:00',
            'employment_type' => 'regular',
        ]);
    }

    public function test_a_pending_or_interviewed_applicant_cannot_become_an_employee(): void
    {
        foreach (['pending', 'interviewed', 'rejected'] as $status) {
            $a = $this->applicant($status);
            $this->hire($a)->assertUnprocessable()->assertJsonValidationErrors('applicant_id');
            $this->assertNull($a->fresh()->employee_id);
        }
        $this->assertSame(0, Employee::count());
        $this->assertSame(0, User::where('role', 'employee')->count());
    }

    public function test_a_hired_applicant_becomes_one_employee_and_is_linked(): void
    {
        $a = $this->applicant('hired');

        $employee = $this->hire($a)->assertCreated()->json('employee');
        $this->assertSame($employee['id'], $a->fresh()->employee_id);

        // the same applicant again (other name and phone): refused, nothing made
        $this->hire($a, 'Santos', '09171234568')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['applicant_id' => "Maria Reyes already has an employee record ({$employee['employee_id']})."]);
        $this->assertSame(1, Employee::count());

        // the list shows the link so Interviews can hide Add as Employee
        $row = collect($this->getJson('/api/applicants')->assertOk()->json())->firstWhere('id', $a->id);
        $this->assertSame($employee['employee_id'], $row['employee']['employee_id']);
    }

    public function test_an_employee_without_an_applicant_is_unaffected(): void
    {
        $this->postJson('/api/employees', [
            'first_name' => 'Jun', 'last_name' => 'Cruz', 'phone' => '09171234569', 'position' => 'Checker',
            'street' => 'Rizal St', 'barangay' => 'Muzon', 'city' => 'San Jose del Monte', 'province' => 'Bulacan',
            'daily_rate' => 695, 'date_hired' => now()->toDateString(), 'shift_start' => '08:00', 'shift_end' => '17:00',
            'employment_type' => 'regular',
        ])->assertCreated();
    }
}
