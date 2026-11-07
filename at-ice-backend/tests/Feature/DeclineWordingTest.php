<?php

namespace Tests\Feature;

use App\Models\Applicant;
use App\Models\DeliveryContract;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LiveEvent;
use App\Models\Product;
use App\Models\StockOutRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Approvers "Decline" (QA flag). Wording only: the stored status values and the
// API routes stay the same ("rejected", "cancelled", .../reject, .../cancel),
// but what people read says declined.
class DeclineWordingTest extends TestCase
{
    use RefreshDatabase;

    public function test_declining_a_pending_contract_says_declined(): void
    {
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@atice.cloud', 'password' => 'secret-password', 'role' => 'admin']);
        Sanctum::actingAs($admin);
        $c = DeliveryContract::create([
            'contract_no' => 'C-0001', 'name' => 'SM chain', 'start_date' => now()->addDay()->toDateString(), 'end_date' => now()->addDays(5)->toDateString(),
            'delivery_days' => ['mon'], 'delivery_time' => '08:00:00', 'items' => [['product_type' => 'bags', 'quantity' => 5]], 'status' => 'pending', 'created_by' => $admin->id,
        ]);

        $this->postJson("/api/delivery-contracts/{$c->id}/cancel")->assertOk()
            ->assertJsonPath('message', 'Contract C-0001 declined. No deliveries were made.');
        $this->assertSame('cancelled', $c->fresh()->status);
    }

    public function test_declining_a_stock_out_request_says_declined(): void
    {
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@atice.cloud', 'password' => 'secret-password', 'role' => 'admin']);
        $maint = User::create(['name' => 'Mae', 'email' => 'mae@atice.cloud', 'password' => 'secret-password', 'role' => 'employee', 'sub_role' => 'maintenance']);
        $p = Product::create(['product_code' => 'SUP-001', 'name' => 'Gloves', 'unit' => 'pair', 'current_stock' => 10, 'minimum_stock' => 2, 'is_active' => true]);
        $r = StockOutRequest::create(['requested_by' => $maint->id, 'product_id' => $p->id, 'item_name' => 'Gloves', 'quantity' => 2, 'purpose' => 'Cleaning', 'status' => 'pending']);
        Sanctum::actingAs($admin);

        $this->putJson("/api/stock-out-requests/{$r->id}/reject", ['rejection_reason' => 'Use the old pair'])->assertOk()
            ->assertJsonPath('message', 'Request declined.');
        $this->assertSame('rejected', $r->fresh()->status);
        $this->putJson("/api/stock-out-requests/{$r->id}/reject", ['rejection_reason' => 'Again'])->assertUnprocessable()
            ->assertJsonPath('message', 'Only pending requests can be declined.');
        $this->assertTrue(LiveEvent::where('message', 'like', '%Declined%')->exists());
    }

    public function test_deciding_a_declined_leave_again_says_declined(): void
    {
        Sanctum::actingAs(User::create(['name' => 'HR', 'email' => 'hr@atice.cloud', 'password' => 'secret-password', 'role' => 'hr']));
        $e = Employee::create(['employee_id' => 'EMP-0001', 'first_name' => 'Ana', 'last_name' => 'Cruz', 'position' => 'Checker', 'daily_rate' => 600, 'date_hired' => '2025-01-01', 'is_active' => true]);
        $l = LeaveRequest::create(['employee_id' => $e->id, 'leave_type' => 'vacation', 'start_date' => '2026-12-01', 'end_date' => '2026-12-01', 'total_days' => 1, 'reason' => 'Trip', 'status' => 'rejected']);

        $this->putJson("/api/leave-requests/{$l->id}/status", ['status' => 'approved'])->assertUnprocessable()
            ->assertJsonPath('message', 'This leave request was already declined.');
    }

    public function test_a_declined_applicant_reads_declined(): void
    {
        Sanctum::actingAs(User::create(['name' => 'HR', 'email' => 'hr@atice.cloud', 'password' => 'secret-password', 'role' => 'hr']));
        $a = Applicant::create(['name' => 'Jun Reyes', 'phone' => '+639171234567', 'position_applied' => 'Bagger', 'status' => 'rejected']);

        $this->putJson("/api/applicants/{$a->id}", ['notes' => 'x'])->assertUnprocessable()
            ->assertJsonPath('message', 'This applicant is already declined. The interview record can no longer be edited.');
    }
}
