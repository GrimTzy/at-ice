<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeMemo;
use App\Models\MemoRecipient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Announcements: picked employees, a role group or all active employees,
// resolved when HR releases them. Disciplinary memos stay one employee each.
class MemoAnnouncementTest extends TestCase
{
    use RefreshDatabase;

    private User $hr;

    private array $emp = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->hr = User::factory()->create(['role' => 'hr']);
        $people = [
            'driver' => ['Driver', 'driver', null, true],
            'checker' => ['Checker', 'employee', 'checker', true],
            'salesman' => ['Salesman', 'employee', 'salesman', true],
            'guard' => ['Guard', null, null, true],
            'gone' => ['Checker', 'employee', 'checker', false],
        ];
        $n = 700;
        foreach ($people as $key => [$position, $role, $sub, $active]) {
            $user = $role ? User::factory()->create(['role' => $role, 'sub_role' => $sub]) : null;
            $this->emp[$key] = Employee::create([
                'user_id' => $user?->id, 'employee_id' => 'EMP-0'.(++$n), 'first_name' => ucfirst($key), 'last_name' => 'Dizon',
                'position' => $position, 'daily_rate' => 600, 'date_hired' => '2026-01-05', 'is_active' => $active,
                'deactivated_at' => $active ? null : now(), 'shift_start' => '07:00', 'shift_end' => '16:00',
            ]);
        }
        Sanctum::actingAs($this->hr);
    }

    private function announce(array $who, bool $send = true)
    {
        return $this->postJson('/api/memos/announcements', [
            'subject' => 'New gate pass rule',
            'body' => 'Starting Monday every truck leaving the plant needs a signed gate pass from the checker on duty.',
            'send' => $send,
        ] + $who);
    }

    private function recipients(int $memoId): array
    {
        return MemoRecipient::where('memo_id', $memoId)->pluck('employee_id')->sort()->values()->all();
    }

    public function test_picked_employees_receive_it(): void
    {
        $id = $this->announce(['mode' => 'employees', 'employee_ids' => [$this->emp['driver']->id, $this->emp['guard']->id]])->assertCreated()->json('id');

        $this->assertSame([$this->emp['driver']->id, $this->emp['guard']->id], $this->recipients($id));
    }

    public function test_a_group_is_resolved_to_its_active_members(): void
    {
        $id = $this->announce(['mode' => 'group', 'group' => 'checkers'])->assertCreated()->json('id');

        $this->assertSame([$this->emp['checker']->id], $this->recipients($id)); // the inactive checker is left out
    }

    public function test_all_active_employees_receive_it_and_inactive_ones_do_not(): void
    {
        $id = $this->announce(['mode' => 'all'])->assertCreated()->json('id');

        $this->assertCount(4, $this->recipients($id));
        $this->assertNotContains($this->emp['gone']->id, $this->recipients($id));
    }

    public function test_a_draft_group_is_resolved_only_when_released(): void
    {
        $id = $this->announce(['mode' => 'group', 'group' => 'salesmen'], false)->assertCreated()->json('id');
        $this->assertSame([], $this->recipients($id));

        $later = Employee::create([
            'employee_id' => 'EMP-0799', 'first_name' => 'Late', 'last_name' => 'Hire', 'position' => 'Salesman',
            'daily_rate' => 600, 'date_hired' => '2026-11-01', 'is_active' => true,
        ]);
        $this->postJson("/api/memos/{$id}/send")->assertOk()->assertJsonPath('status', 'sent');

        $this->assertSame([$this->emp['salesman']->id, $later->id], $this->recipients($id));
    }

    public function test_an_inactive_employee_cannot_be_picked(): void
    {
        $this->announce(['mode' => 'employees', 'employee_ids' => [$this->emp['gone']->id]])
            ->assertStatus(422)->assertJsonValidationErrors('employee_ids.0');
    }

    public function test_recipients_see_and_acknowledge_it_and_others_do_not(): void
    {
        $id = $this->announce(['mode' => 'group', 'group' => 'drivers'])->assertCreated()->json('id');

        app('auth')->forgetGuards();
        Sanctum::actingAs(User::find($this->emp['driver']->user_id));
        $this->getJson('/api/my-memos')->assertOk()->assertJsonPath('0.kind', 'announcement')->assertJsonPath('0.subject', 'New gate pass rule');
        $this->postJson("/api/my-memos/{$id}/acknowledge")->assertOk();
        $this->assertNotNull(MemoRecipient::where('memo_id', $id)->value('acknowledged_at'));

        app('auth')->forgetGuards();
        Sanctum::actingAs(User::find($this->emp['checker']->user_id));
        $this->getJson('/api/my-memos')->assertOk()->assertJsonCount(0);
        $this->postJson("/api/my-memos/{$id}/acknowledge")->assertNotFound();
    }

    public function test_disciplinary_memos_are_unchanged_one_employee_each(): void
    {
        $this->postJson('/api/memos', [
            'employee_id' => $this->emp['checker']->id, 'violation' => 'insubordination', 'incident_date' => now(config('atice.timezone'))->toDateString(),
            'subject' => 'Verbal Warning: Insubordination', 'body' => 'Refused to recount the trucks when the supervisor asked.', 'send' => 1,
        ])->assertCreated()->assertJsonPath('employee_id', $this->emp['checker']->id)->assertJsonPath('status', 'sent');

        $this->postJson('/api/memos', [
            'violation' => 'insubordination', 'incident_date' => now(config('atice.timezone'))->toDateString(),
            'subject' => 'No employee', 'body' => 'A disciplinary memo always needs an employee picked.',
        ])->assertStatus(422)->assertJsonValidationErrors('employee_id');
        $this->assertSame(0, EmployeeMemo::where('kind', 'announcement')->count());
    }

    public function test_non_hr_cannot_send_announcements(): void
    {
        app('auth')->forgetGuards();
        Sanctum::actingAs(User::find($this->emp['driver']->user_id));

        $this->announce(['mode' => 'all'])->assertForbidden();
    }
}
