<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\SupportMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Contact Support: Admin escalates a ticket with a reason; the customer sees
// "Escalated" but cannot escalate.
class SupportEscalationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $storeUser;

    private SupportMessage $ticket;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->storeUser = User::factory()->create(['role' => 'customer']);
        $store = Customer::create([
            'name' => 'Savemore Sapang Palay', 'customer_code' => 'CUST-SM-031', 'branch_code' => 'SM-SJDM-031',
            'invoice_code' => 'SM', 'customer_type' => 'supermarket', 'is_active' => true, 'user_id' => $this->storeUser->id,
        ]);
        $this->ticket = SupportMessage::create([
            'customer_id' => $store->id, 'user_id' => $this->storeUser->id, 'topic' => 'short_delivery',
            'message' => 'We got 18 sacks but the invoice says 20.', 'status' => 'open',
        ]);
    }

    public function test_admin_escalates_with_a_reason_and_it_is_audited(): void
    {
        Sanctum::actingAs($this->admin);

        $this->patchJson("/api/support-messages/{$this->ticket->id}/escalate", ['reason' => 'Third short delivery this week'])
            ->assertOk()
            ->assertJsonPath('escalation_reason', 'Third short delivery this week')
            ->assertJsonPath('escalator.id', $this->admin->id);

        $this->assertNotNull($this->ticket->fresh()->escalated_at);
        $this->assertSame('open', $this->ticket->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'support.escalated', 'user_id' => $this->admin->id, 'subject_id' => $this->ticket->id]);
    }

    public function test_a_reason_of_at_least_five_characters_is_required(): void
    {
        Sanctum::actingAs($this->admin);

        $this->patchJson("/api/support-messages/{$this->ticket->id}/escalate", [])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->patchJson("/api/support-messages/{$this->ticket->id}/escalate", ['reason' => 'late'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->assertNull($this->ticket->fresh()->escalated_at);
    }

    public function test_the_customer_cannot_escalate_but_sees_escalated(): void
    {
        Sanctum::actingAs($this->storeUser);
        $this->patchJson("/api/support-messages/{$this->ticket->id}/escalate", ['reason' => 'Please hurry up'])->assertForbidden();
        $this->assertNull($this->ticket->fresh()->escalated_at);

        $this->ticket->update(['escalated_at' => now(), 'escalated_by' => $this->admin->id, 'escalation_reason' => 'Manager to call the store']);
        $mine = $this->getJson('/api/my-support-messages')->assertOk()->json();
        $this->assertNotNull($mine[0]['escalated_at']);
        $this->assertArrayNotHasKey('escalation_reason', $mine[0]);
    }

    public function test_de_escalate_clears_it_and_is_audited(): void
    {
        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/support-messages/{$this->ticket->id}/escalate", ['reason' => 'Needs the plant manager'])->assertOk();

        $this->patchJson("/api/support-messages/{$this->ticket->id}/de-escalate")->assertOk()->assertJsonPath('escalated_at', null);
        $this->assertDatabaseHas('audit_logs', ['action' => 'support.de_escalated', 'subject_id' => $this->ticket->id]);
        $this->patchJson("/api/support-messages/{$this->ticket->id}/de-escalate")->assertStatus(422);
    }

    public function test_escalated_tickets_in_progress_count_in_the_menu_badge(): void
    {
        $this->ticket->update(['status' => 'in_progress']);
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/nav-counts/admin')->assertOk()->assertJsonPath('/admin/support-inbox', 0);

        $this->patchJson("/api/support-messages/{$this->ticket->id}/escalate", ['reason' => 'Store threatens to cancel'])->assertOk();

        $this->getJson('/api/nav-counts/admin')->assertOk()->assertJsonPath('/admin/support-inbox', 1);
    }

    public function test_a_resolved_ticket_cannot_be_escalated(): void
    {
        $this->ticket->update(['status' => 'resolved']);
        Sanctum::actingAs($this->admin);

        $this->patchJson("/api/support-messages/{$this->ticket->id}/escalate", ['reason' => 'Too late now'])->assertStatus(422);
    }
}
