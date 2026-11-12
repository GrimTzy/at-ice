<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Support\LeaveCredits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// The leave the law gives (QA review, 2026-11-11): no credits and no regular
// status needed, each with its own conditions and proof, paid in full.
class LeaveStatutoryTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->travelTo(now('Asia/Manila')->setDate(2026, 10, 14)->setTime(10, 0));
        $this->user = User::factory()->create(['role' => 'employee', 'sub_role' => 'checker']);
        // probationary, hired 4 months ago
        $this->employee = Employee::create([
            'user_id' => $this->user->id, 'employee_id' => 'EMP-0003', 'first_name' => 'Christine', 'last_name' => 'Bautista', 'position' => 'Checker',
            'daily_rate' => 610, 'date_hired' => '2026-06-15', 'employment_type' => 'regular', 'shift' => 'day', 'is_active' => true,
            'employment_status' => 'probationary', 'sex' => 'female',
        ]);
        Sanctum::actingAs($this->user);
    }

    private function file(array $data)
    {
        return $this->post('/api/my-leave-requests', $data + ['reason' => 'Due date Oct 30', 'attachment' => UploadedFile::fake()->create('proof.pdf', 80, 'application/pdf')],
            ['Accept' => 'application/json']);
    }

    public function test_maternity_leave_is_filed_while_probationary_and_paid_in_full(): void
    {
        // 105 calendar days, Oct 26 to Feb 7
        $this->file(['leave_type' => 'maternity', 'start_date' => '2026-10-26', 'end_date' => '2027-02-07'])->assertCreated();
        $leave = LeaveRequest::first();
        $this->assertEquals(105, (float) $leave->total_days);

        Sanctum::actingAs(User::factory()->create(['role' => 'hr']));
        $this->putJson("/api/leave-requests/{$leave->id}/status", ['status' => 'approved'])->assertOk();
        $leave->refresh();
        $this->assertTrue($leave->is_paid);
        $this->assertEquals(105, (float) $leave->paid_days);
        $this->assertSame(0.0, LeaveCredits::for($this->employee->fresh())['used']); // no credits used
    }

    public function test_each_leave_keeps_its_own_conditions(): void
    {
        // a VL still waits for regular status
        $this->file(['leave_type' => 'vacation', 'start_date' => '2026-11-03', 'end_date' => '2026-11-03'])->assertStatus(422);

        // paternity is for male employees
        $this->file(['leave_type' => 'paternity', 'start_date' => '2026-10-19', 'end_date' => '2026-10-21'])
            ->assertStatus(422)->assertJsonPath('errors.leave_type.0', 'Paternity leave is for male employees (RA 8187).');

        // solo parent leave needs 6 months of service
        $this->file(['leave_type' => 'solo_parent', 'start_date' => '2026-10-19', 'end_date' => '2026-10-19'])
            ->assertStatus(422)->assertJsonPath('errors.leave_type.0', 'Solo parent leave needs 6 months of service first (RA 11861).');

        // the proof is required
        $this->post('/api/my-leave-requests', ['leave_type' => 'vawc', 'start_date' => '2026-10-19', 'end_date' => '2026-10-20', 'reason' => 'Protection order hearing'], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('attachment');
    }

    public function test_paternity_is_at_most_7_work_days_and_solo_parent_7_a_year(): void
    {
        $this->employee->update(['sex' => 'male', 'first_name' => 'Ramil', 'last_name' => 'Santos', 'date_hired' => '2025-02-03']);
        // Mon Oct 19 to Wed Oct 28 = 8 work days
        $this->file(['leave_type' => 'paternity', 'start_date' => '2026-10-19', 'end_date' => '2026-10-28'])
            ->assertStatus(422)->assertJsonPath('errors.end_date.0', 'Paternity leave is at most 7 work days (RA 8187). This request is 8.');
        $this->file(['leave_type' => 'paternity', 'start_date' => '2026-10-19', 'end_date' => '2026-10-27'])->assertCreated();

        $this->file(['leave_type' => 'solo_parent', 'start_date' => '2026-11-16', 'end_date' => '2026-11-20'])->assertCreated(); // 5 days
        $this->file(['leave_type' => 'solo_parent', 'start_date' => '2026-11-23', 'end_date' => '2026-11-25'])
            ->assertStatus(422)->assertJsonPath('errors.end_date.0', 'Solo parent leave is at most 7 work days a year (RA 11861). 5 already filed in 2026.');
    }
}
