<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeMemo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Formal memos: HR drafts and endorses, Admin approves or returns, HR releases.
// Verbal and first written warnings are still sent by HR directly.
class MemoApprovalTest extends TestCase
{
    use RefreshDatabase;

    private User $hr;

    private User $admin;

    private User $empUser;

    private Employee $emp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hr = User::factory()->create(['role' => 'hr']);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->empUser = User::factory()->create(['role' => 'employee', 'sub_role' => 'salesman']);
        $this->emp = Employee::create([
            'user_id' => $this->empUser->id, 'employee_id' => 'EMP-0601', 'first_name' => 'Czar', 'last_name' => 'Justo',
            'position' => 'Salesman', 'daily_rate' => 650, 'date_hired' => '2026-01-05', 'is_active' => true,
            'shift_start' => '07:00', 'shift_end' => '16:00',
        ]);
    }

    private function memo(string $level, string $violation = 'insubordination'): EmployeeMemo
    {
        return EmployeeMemo::create([
            'memo_no' => EmployeeMemo::nextMemoNo(), 'employee_id' => $this->emp->id, 'violation' => $violation,
            'category' => EmployeeMemo::categoryOf($violation), 'level' => $level, 'offense_no' => 1,
            'status' => 'draft', 'source' => 'manual', 'subject' => 'Memo', 'incident_date' => '2026-11-03',
            'body' => 'Refused the route assigned by the supervisor on November 3.',
        ]);
    }

    private function as(User $user): void
    {
        app('auth')->forgetGuards();
        Sanctum::actingAs($user);
    }

    public function test_a_formal_memo_cannot_be_sent_without_approval(): void
    {
        $final = $this->memo('final_written');
        $this->as($this->hr);

        $this->postJson("/api/memos/{$final->id}/send")->assertStatus(422);
        $this->postJson('/api/memos', [
            'employee_id' => $this->emp->id, 'violation' => 'cod_theft', 'incident_date' => '2026-11-03',
            'subject' => 'Notice to Explain', 'body' => 'Collections from three stores were not remitted on November 3.', 'send' => 1,
        ])->assertStatus(422);
        $this->assertSame('draft', $final->fresh()->status);
    }

    public function test_the_full_flow_endorse_approve_release(): void
    {
        $nte = $this->memo('nte');
        $this->as($this->hr);
        $this->postJson("/api/memos/{$nte->id}/endorse")->assertOk()->assertJsonPath('status', 'for_approval');
        $this->postJson("/api/memos/{$nte->id}/send")->assertStatus(422); // still waiting

        $this->as($this->admin);
        $this->getJson('/api/memo-approvals')->assertOk()->assertJsonPath('memos.0.id', $nte->id);
        $this->getJson('/api/nav-counts/admin')->assertJsonPath('/admin/memo-approvals', 1);
        $this->postJson("/api/memos/{$nte->id}/approve")->assertOk()->assertJsonPath('status', 'approved');

        $this->as($this->hr);
        $this->postJson("/api/memos/{$nte->id}/send")->assertOk()->assertJsonPath('status', 'awaiting_physical');
        foreach (['memo.endorsed', 'memo.approved', 'memo.send'] as $action) {
            $this->assertDatabaseHas('audit_logs', ['action' => $action, 'subject_id' => $nte->id]);
        }
    }

    public function test_approval_by_non_admin_is_403(): void
    {
        $nte = $this->memo('nte');
        $this->as($this->hr);
        $this->postJson("/api/memos/{$nte->id}/endorse")->assertOk();

        $this->postJson("/api/memos/{$nte->id}/approve")->assertForbidden();
        $this->postJson("/api/memos/{$nte->id}/return", ['reason' => 'Looks fine to me'])->assertForbidden();
        $this->getJson('/api/memo-approvals')->assertForbidden();
        $this->assertSame('for_approval', $nte->fresh()->status);
    }

    public function test_a_returned_memo_goes_back_to_draft_with_the_reason(): void
    {
        $final = $this->memo('final_written');
        $this->as($this->hr);
        $this->postJson("/api/memos/{$final->id}/endorse")->assertOk();

        $this->as($this->admin);
        $this->postJson("/api/memos/{$final->id}/return", [])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/memos/{$final->id}/return", ['reason' => 'Attach the supervisor report first'])
            ->assertOk()->assertJsonPath('status', 'draft')->assertJsonPath('return_reason', 'Attach the supervisor report first');
        $this->assertDatabaseHas('audit_logs', ['action' => 'memo.returned', 'subject_id' => $final->id]);

        $this->as($this->hr);
        $this->patchJson("/api/memos/{$final->id}", ['body' => 'Refused the route assigned on November 3. Supervisor report attached.'])->assertOk();
    }

    public function test_the_employee_never_sees_unreleased_memos(): void
    {
        $draft = $this->memo('final_written');
        $waiting = $this->memo('nte', 'misconduct');
        $approved = $this->memo('nte', 'awol');
        $waiting->update(['status' => 'for_approval']);
        $approved->update(['status' => 'approved']);

        $this->as($this->empUser);
        $this->getJson('/api/my-memos')->assertOk()->assertJsonCount(0);
        foreach ([$draft, $waiting, $approved] as $m) {
            $this->postJson("/api/my-memos/{$m->id}/acknowledge")->assertNotFound();
        }
    }

    public function test_verbal_and_first_written_warnings_are_still_sent_directly(): void
    {
        $verbal = $this->memo('verbal_warning');
        $this->as($this->hr);

        $this->postJson("/api/memos/{$verbal->id}/endorse")->assertStatus(422);
        $this->postJson("/api/memos/{$verbal->id}/send")->assertOk()->assertJsonPath('status', 'sent');
    }
}
