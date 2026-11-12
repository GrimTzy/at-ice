<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Delivery;
use App\Models\DeliveryProof;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Product;
use App\Models\Receipt;
use App\Models\StockMovement;
use App\Models\StockOutRequest;
use App\Models\SupportMessage;
use App\Models\SupportReply;
use App\Models\User;
use App\Support\DriverAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// The side-menu badges, bottom tabs and the bell come from one source
// (App\Support\NavCounts), sent with every live-feed poll, for every role
// (owner, 2026-11-12). "To do" counts go down when the item is handled; news
// counts (Maintenance, customer, own leave) when the page is opened.
class NavCountsTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, ?string $sub = null): User
    {
        return User::create(['name' => ucfirst($sub ?? $role), 'email' => ($sub ?? $role).'@atice.cloud', 'password' => 'secret-password', 'role' => $role, 'sub_role' => $sub]);
    }

    private function counts(User $u): array
    {
        Sanctum::actingAs($u);

        return $this->getJson('/api/nav-counts')->assertOk()->json('counts');
    }

    public function test_maintenance_sees_an_answered_request_on_the_badge_and_the_live_feed_until_opened(): void
    {
        $maint = $this->user('employee', 'maintenance');
        $admin = $this->user('admin');
        $req = StockOutRequest::create(['requested_by' => $maint->id, 'item_name' => 'Brake pads', 'quantity' => 2, 'purpose' => 'Truck 3 brakes', 'status' => 'pending']);

        $this->assertSame(0, $this->counts($maint)['/maintenance/stock-out-request']);
        $this->assertSame(1, $this->counts($admin)['/admin/stock-out-approvals']);

        $this->travel(1)->minutes();
        $req->update(['status' => 'approved', 'approved_by' => $admin->id]);

        // the same number reaches the bell and the tab through the live-feed poll
        Sanctum::actingAs($maint);
        $this->assertSame(1, $this->getJson('/api/live-feed?after=0')->assertOk()->json('nav.counts./maintenance/stock-out-request'));
        $this->assertContains('/maintenance/stock-out-request', $this->getJson('/api/nav-counts')->json('seen_paths'));
        // Admin still has it: approved, waiting for release
        $this->assertSame(1, $this->counts($admin)['/admin/stock-out-approvals']);

        // opening the page clears it
        Sanctum::actingAs($maint);
        $this->postJson('/api/nav-counts/seen', ['path' => '/maintenance/stock-out-request'])
            ->assertOk()->assertJsonPath('counts./maintenance/stock-out-request', 0);

        // released: gone for Admin
        $req->update(['status' => 'released']);
        $this->assertSame(0, $this->counts($admin)['/admin/stock-out-approvals']);
    }

    public function test_hr_leave_badge_goes_down_when_handled_and_the_employee_is_told(): void
    {
        $hr = $this->user('hr');
        $checker = $this->user('employee', 'checker');
        $emp = Employee::create(['user_id' => $checker->id, 'employee_id' => 'EMP-0100', 'first_name' => 'Ana', 'last_name' => 'Cruz', 'position' => 'Checker', 'daily_rate' => 600, 'date_hired' => '2026-01-01', 'is_active' => true]);
        $leave = LeaveRequest::create(['employee_id' => $emp->id, 'leave_type' => 'sick', 'start_date' => '2026-12-01', 'end_date' => '2026-12-01', 'total_days' => 1, 'reason' => 'Fever', 'status' => 'pending']);

        $this->assertSame(1, $this->counts($hr)['/hr/leave-requests']);
        $this->assertSame(0, $this->counts($checker)['/my-leave']);

        $this->travel(1)->minutes();
        $leave->update(['status' => 'approved']);
        $this->assertSame(0, $this->counts($hr)['/hr/leave-requests']);
        $this->assertSame(1, $this->counts($checker)['/my-leave']);
    }

    public function test_checker_driver_and_customer_counts(): void
    {
        $checker = $this->user('employee', 'checker');
        $driver = $this->user('driver');
        $customerUser = $this->user('customer');
        $store = Customer::create(['name' => 'Starmall', 'customer_code' => 'CUST-STA-001', 'branch_code' => 'STA-1', 'is_active' => true, 'user_id' => $customerUser->id]);
        $today = DriverAvailability::today();
        $d = Delivery::create(['delivery_no' => 'DEL-0001', 'driver_id' => $driver->id, 'customer_id' => $store->id, 'status' => 'pending', 'delivery_type' => 'outbound', 'scheduled_date' => $today, 'prepared_by' => $checker->id]);
        $product = Product::create(['product_code' => 'SUP-001', 'name' => 'Gloves', 'unit' => 'pair', 'current_stock' => 10, 'minimum_stock' => 2, 'is_active' => true]);
        StockMovement::create(['product_id' => $product->id, 'type' => 'stock_in', 'quantity' => 1, 'stock_before' => 10, 'stock_after' => 11, 'status' => 'pending', 'entered_by' => $checker->id]);
        // an earlier delivery with an unpaid invoice
        $done = Delivery::create(['delivery_no' => 'DEL-0000', 'driver_id' => $driver->id, 'customer_id' => $store->id, 'status' => 'delivered', 'delivery_type' => 'outbound', 'scheduled_date' => '2026-01-05', 'prepared_by' => $checker->id]);
        $proof = DeliveryProof::create(['delivery_id' => $done->id, 'salesman_id' => $checker->id, 'quantity' => 1, 'photo_path' => 'x.jpg', 'confirmed_at' => now()]);
        Receipt::create(['receipt_no' => 'R-1', 'delivery_id' => $done->id, 'delivery_proof_id' => $proof->id, 'customer_id' => $store->id, 'salesman_id' => $checker->id, 'quantity' => 1, 'unit_price' => 50, 'total_amount' => 50, 'payment_status' => 'unpaid']);

        $checkerCounts = $this->counts($checker);
        $this->assertSame(1, $checkerCounts['/checker/ice-loading']);
        $this->assertSame(1, $checkerCounts['/checker/stock-movements']);
        $this->assertSame(1, $this->counts($driver)['/driver/deliveries']);
        $this->assertSame(1, $this->counts($customerUser)['/customer/receipts']);

        // a reply from the company is news for the customer until Contact Support is opened
        $ticket = SupportMessage::create(['customer_id' => $store->id, 'user_id' => $customerUser->id, 'topic' => 'other', 'subject' => 'Hi', 'message' => 'Question about my bill', 'status' => 'open']);
        SupportReply::create(['support_message_id' => $ticket->id, 'user_id' => $checker->id, 'kind' => 'reply', 'body' => 'Here is the answer']);
        $this->assertSame(1, $this->counts($customerUser)['/customer/support']);
        $this->postJson('/api/nav-counts/seen', ['path' => '/customer/support'])->assertOk()->assertJsonPath('counts./customer/support', 0);

        $d->update(['status' => 'delivered']);
        $this->assertSame(0, $this->counts($driver)['/driver/deliveries']);
    }

    public function test_the_older_admin_and_hr_endpoints_return_the_same_plain_counts(): void
    {
        Sanctum::actingAs($this->user('admin'));
        $this->getJson('/api/nav-counts/admin')->assertOk()->assertJsonPath('/admin/deliveries', 0);
        Sanctum::actingAs($this->user('hr'));
        $this->getJson('/api/nav-counts/hr')->assertOk()->assertJsonPath('/hr/leave-requests', 0);
    }

    public function test_only_news_pages_can_be_marked_seen(): void
    {
        Sanctum::actingAs($this->user('admin'));
        $this->postJson('/api/nav-counts/seen', ['path' => '/admin/deliveries'])->assertOk();
        $this->assertDatabaseCount('nav_seen', 0);
    }
}
