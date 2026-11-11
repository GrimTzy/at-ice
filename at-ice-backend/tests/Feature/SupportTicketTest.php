<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SupportTicketTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $customerUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@atice.cloud', 'password' => 'secret-password', 'role' => 'admin']);
        $this->customerUser = User::create(['name' => 'Tobistro Food', 'email' => 'tob@example.com', 'password' => 'secret-password', 'role' => 'customer']);
        Customer::create(['name' => 'Tobistro Food', 'customer_code' => 'CUST-TOB-001', 'invoice_code' => 'TOB', 'is_active' => true, 'user_id' => $this->customerUser->id]);
    }

    private function file(string $topic): int
    {
        Sanctum::actingAs($this->customerUser);

        return $this->postJson('/api/my-support-messages', ['topic' => $topic, 'message' => 'Please check this.'])->assertCreated()->json('id');
    }

    // The inbox "New" badge: read_at is per Admin and saved when the ticket is opened
    public function test_opening_a_ticket_marks_it_read_for_that_admin_only(): void
    {
        $id = $this->file('late_delivery');
        $other = User::create(['name' => 'Second Admin', 'email' => 'admin2@atice.cloud', 'password' => 'secret-password', 'role' => 'admin']);

        Sanctum::actingAs($this->admin);
        $this->assertNull(collect($this->getJson('/api/support-messages')->json('messages'))->firstWhere('id', $id)['read_at']);
        $this->getJson("/api/support-messages/{$id}")->assertOk();
        $this->assertNotNull(collect($this->getJson('/api/support-messages')->json('messages'))->firstWhere('id', $id)['read_at']);

        Sanctum::actingAs($other);
        $this->assertNull(collect($this->getJson('/api/support-messages')->json('messages'))->firstWhere('id', $id)['read_at']);
    }

    public function test_a_message_becomes_a_numbered_ticket_with_a_priority_from_its_topic(): void
    {
        $first = $this->file('staff_conduct');
        $second = $this->file('price_inquiry');
        $day = now()->format('Ymd');

        $this->assertDatabaseHas('support_messages', ['id' => $first, 'ticket_no' => "TKT-{$day}-001", 'priority' => 'high']);
        $this->assertDatabaseHas('support_messages', ['id' => $second, 'ticket_no' => "TKT-{$day}-002", 'priority' => 'low']);
    }

    public function test_the_customer_sees_replies_and_the_resolution_but_never_internal_notes(): void
    {
        $id = $this->file('late_delivery');

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/support-messages/{$id}/replies", ['kind' => 'note', 'body' => 'Checking the route'])->assertOk();
        $this->postJson("/api/support-messages/{$id}/replies", ['kind' => 'reply', 'body' => 'We are on it'])->assertOk()
            ->assertJsonPath('status', 'in_progress');
        $this->patchJson("/api/support-messages/{$id}/resolve", ['resolution_note' => 'Delivered at 3 PM'])->assertOk()
            ->assertJsonPath('status', 'resolved');

        Sanctum::actingAs($this->customerUser);
        $ticket = $this->getJson('/api/my-support-messages')->assertOk()->json('0');
        $this->assertSame(['We are on it'], array_column($ticket['replies'], 'body'));
        $this->assertSame('Delivered at 3 PM', $ticket['resolution_note']);
    }

    public function test_resolving_needs_a_note_closing_needs_a_resolved_ticket_and_both_are_audited(): void
    {
        $id = $this->file('billing');

        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/support-messages/{$id}/resolve", ['resolution_note' => ''])->assertStatus(422)->assertJsonValidationErrors('resolution_note');
        $this->patchJson("/api/support-messages/{$id}/close")->assertStatus(422);
        $this->patchJson("/api/support-messages/{$id}/resolve", ['resolution_note' => 'Invoice corrected'])->assertOk();
        $this->patchJson("/api/support-messages/{$id}/close")->assertOk()->assertJsonPath('status', 'closed');
        $this->postJson("/api/support-messages/{$id}/replies", ['kind' => 'reply', 'body' => 'Hello'])->assertStatus(422);

        $this->assertSame(2, DB::table('audit_logs')->whereIn('action', ['support.resolve', 'support.close'])->count());
    }

    public function test_one_open_ticket_per_topic_a_cap_on_open_tickets_and_no_emergency_supply_topic(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class); // the rate limit has its own test

        $id = $this->file('late_delivery');
        $this->postJson('/api/my-support-messages', ['topic' => 'late_delivery', 'message' => 'Again late.'])
            ->assertStatus(422)->assertJsonValidationErrors('topic');

        $this->file('billing');
        $this->file('damaged_ice');
        Settings::set('support.max_open_tickets', 3);
        Sanctum::actingAs($this->customerUser);
        $this->postJson('/api/my-support-messages', ['topic' => 'wrong_quantity', 'message' => 'Short by two.'])
            ->assertStatus(422)->assertJsonValidationErrors('topic');

        // emergency supply is no longer offered: the client does not allow emergency deliveries
        $this->postJson('/api/my-support-messages', ['topic' => 'emergency_supply', 'message' => 'Need 20 sacks now.'])
            ->assertStatus(422)->assertJsonValidationErrors('topic');

        // once resolved, the same topic can be filed again
        Settings::set('support.max_open_tickets', 10);
        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/support-messages/{$id}/resolve", ['resolution_note' => 'Delivered late, apologized'])->assertOk();
        Sanctum::actingAs($this->customerUser);
        $this->postJson('/api/my-support-messages', ['topic' => 'late_delivery', 'message' => 'Late again today.'])->assertCreated();
    }

    public function test_new_tickets_are_rate_limited(): void
    {
        $this->file('late_delivery');
        $this->file('billing');
        $this->file('damaged_ice');
        $this->postJson('/api/my-support-messages', ['topic' => 'feedback', 'message' => 'Great service.'])->assertStatus(429);
    }

    public function test_the_customer_replies_only_on_their_own_open_ticket_and_the_admin_sees_it(): void
    {
        $id = $this->file('billing');
        $this->postJson("/api/my-support-messages/{$id}/replies", ['body' => 'Invoice PG-001 is wrong'])->assertCreated();

        $other = User::create(['name' => 'Other', 'email' => 'other@example.com', 'password' => 'secret-password', 'role' => 'customer']);
        Customer::create(['name' => 'Other Store', 'customer_code' => 'CUST-OTH-001', 'invoice_code' => 'OTH', 'is_active' => true, 'user_id' => $other->id]);
        Sanctum::actingAs($other);
        $this->postJson("/api/my-support-messages/{$id}/replies", ['body' => 'Not mine'])->assertNotFound();

        Sanctum::actingAs($this->admin);
        $this->getJson("/api/support-messages/{$id}")->assertOk()->assertJsonPath('replies.0.kind', 'customer');
        $this->patchJson("/api/support-messages/{$id}/resolve", ['resolution_note' => 'Invoice corrected'])->assertOk();

        Sanctum::actingAs($this->customerUser);
        $this->postJson("/api/my-support-messages/{$id}/replies", ['body' => 'One more thing'])->assertStatus(422);
        $this->assertSame(['customer'], array_column($this->getJson('/api/my-support-messages')->json('0.replies'), 'kind'));
    }

    public function test_only_the_admin_handles_tickets_and_assigns_only_admins(): void
    {
        $id = $this->file('other');

        Sanctum::actingAs($this->customerUser);
        $this->getJson('/api/support-messages')->assertForbidden();
        $this->patchJson("/api/support-messages/{$id}", ['priority' => 'high'])->assertForbidden();

        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/support-messages/{$id}", ['assigned_to' => $this->customerUser->id])->assertStatus(422);
        $this->patchJson("/api/support-messages/{$id}", ['assigned_to' => $this->admin->id, 'priority' => 'high'])->assertOk()
            ->assertJsonPath('assignee.name', 'Admin')->assertJsonPath('priority', 'high');
    }
}
