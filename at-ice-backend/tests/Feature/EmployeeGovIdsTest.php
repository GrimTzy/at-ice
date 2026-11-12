<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// SSS, PhilHealth, Pag-IBIG and TIN stay optional, but HR sees which are missing
// (QA review, 2026-11-11).
class EmployeeGovIdsTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_government_numbers_are_listed_for_hr(): void
    {
        $user = User::create(['name' => 'Ramil Santos', 'email' => 'santos.0004@atice.cloud', 'password' => 'secret-password', 'role' => 'employee', 'sub_role' => 'checker']);
        $emp = Employee::create([
            'user_id' => $user->id, 'employee_id' => 'EMP-0004', 'first_name' => 'Ramil', 'last_name' => 'Santos', 'position' => 'Checker',
            'daily_rate' => 610, 'date_hired' => '2026-02-02', 'is_active' => true, 'sss_no' => '34-5678901-2',
        ]);

        $this->assertSame(['PhilHealth', 'Pag-IBIG', 'TIN'], $emp->missingGovIds());

        Sanctum::actingAs(User::create(['name' => 'Lorna Fabian', 'email' => 'hr@atice.cloud', 'password' => 'secret-password', 'role' => 'hr']));
        $row = collect($this->getJson('/api/employees')->assertOk()->json())->firstWhere('employee_id', 'EMP-0004');
        $this->assertSame(['PhilHealth', 'Pag-IBIG', 'TIN'], $row['missing_ids']);

        $emp->update(['philhealth_no' => '12-345678901-2', 'pagibig_no' => '1234-5678-9012', 'tin_no' => '123-456-789-000']);
        $this->assertSame([], $emp->fresh()->missingGovIds());
    }
}
