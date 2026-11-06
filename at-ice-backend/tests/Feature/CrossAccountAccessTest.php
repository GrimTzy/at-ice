<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Delivery;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\StockOutRequest;
use App\Models\SupportMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// QA: one signed-in account must never read or change another account's
// records by guessing an id (insecure direct object references).
class CrossAccountAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_maintenance_stock_out_details_follow_the_same_ownership_as_the_list(): void
    {
        $owner = User::factory()->create(['role' => 'employee', 'sub_role' => 'maintenance']);
        $other = User::factory()->create(['role' => 'employee', 'sub_role' => 'maintenance']);
        $request = StockOutRequest::create([
            'requested_by' => $owner->id, 'item_name' => 'Test part', 'quantity' => 1,
            'purpose' => 'Owner-only maintenance request', 'status' => 'pending',
        ]);

        Sanctum::actingAs($owner);
        $this->getJson('/api/stock-out-requests')->assertOk()->assertJsonPath('data.0.id', $request->id);
        $this->getJson("/api/stock-out-requests/{$request->id}")->assertOk()->assertJsonPath('id', $request->id);

        Sanctum::actingAs($other);
        $this->getJson('/api/stock-out-requests')->assertOk()->assertJsonPath('data', []);
        $this->getJson("/api/stock-out-requests/{$request->id}")->assertNotFound()->assertExactJson(['message' => 'Not found.']);
        $this->getJson('/api/stock-out-requests/999999')->assertNotFound()->assertExactJson(['message' => 'Not found.']);
    }

    public function test_admin_retains_stock_out_list_and_detail_access(): void
    {
        $owner = User::factory()->create(['role' => 'employee', 'sub_role' => 'maintenance']);
        $request = StockOutRequest::create([
            'requested_by' => $owner->id, 'item_name' => 'Test part', 'quantity' => 1,
            'purpose' => 'Maintenance request', 'status' => 'pending',
        ]);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->getJson('/api/stock-out-requests')->assertOk()->assertJsonPath('data.0.id', $request->id);
        $this->getJson("/api/stock-out-requests/{$request->id}")->assertOk()->assertJsonPath('id', $request->id);
    }

    public function test_other_roles_remain_forbidden_from_stock_out_details(): void
    {
        $owner = User::factory()->create(['role' => 'employee', 'sub_role' => 'maintenance']);
        $request = StockOutRequest::create([
            'requested_by' => $owner->id, 'item_name' => 'Test part', 'quantity' => 1,
            'purpose' => 'Maintenance request', 'status' => 'pending',
        ]);
        foreach ([['hr', null], ['customer', null], ['driver', null], ['employee', 'checker'], ['employee', 'salesman'], ['employee', null]] as [$role, $subRole]) {
            Sanctum::actingAs(User::factory()->create(['role' => $role, 'sub_role' => $subRole]));
            $this->getJson('/api/stock-out-requests')->assertForbidden();
            $this->getJson("/api/stock-out-requests/{$request->id}")->assertForbidden();
        }
    }

    private function store(string $name, string $code): array
    {
        $user = User::create(['name' => $name, 'email' => strtolower($code).'@example.com', 'password' => 'secret-password', 'role' => 'customer']);
        $customer = Customer::create(['name' => $name, 'customer_code' => $code, 'invoice_code' => substr($code, 5, 3), 'is_active' => true, 'user_id' => $user->id]);

        return [$user, $customer];
    }

    public function test_a_customer_cannot_touch_another_customers_delivery_or_ticket(): void
    {
        [$userA, $storeA] = $this->store('Store A', 'CUST-AAA-001');
        [$userB] = $this->store('Store B', 'CUST-BBB-001');
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@atice.cloud', 'password' => 'secret-password', 'role' => 'admin']);

        $delivery = Delivery::create(['delivery_no' => 'DEL-0001', 'customer_id' => $storeA->id, 'delivery_type' => 'outbound', 'status' => 'delivered', 'scheduled_date' => now()->toDateString(), 'ordered_at' => now(), 'prepared_by' => $admin->id]);
        $delivery->items()->create(['product_type' => 'small_sack', 'quantity_ordered' => 5, 'unit_price' => 150]);
        $ticket = SupportMessage::create(['customer_id' => $storeA->id, 'user_id' => $userA->id, 'topic' => 'late_delivery', 'message' => 'Where is it? Secret-A-text', 'status' => 'open']);

        Sanctum::actingAs($userB);
        $this->postJson("/api/my-deliveries/{$delivery->id}/top-up", ['items' => ['small_sack' => 5], 'deliver_by' => '16:00'])->assertNotFound();
        $this->postJson("/api/my-deliveries/{$delivery->id}/adjustment-requests", ['items' => ['small_sack' => 1]])->assertNotFound();
        $this->postJson("/api/my-support-messages/{$ticket->id}/replies", ['body' => 'Not my ticket'])->assertNotFound();
        $this->getJson('/api/my-payment-proofs/1/file')->assertNotFound();

        $mine = $this->getJson('/api/my-delivery-history')->assertOk()->json();
        $this->assertStringNotContainsString('DEL-0001', json_encode($mine), "Store B's history lists Store A's delivery");
        $tickets = $this->getJson('/api/my-support-messages')->assertOk()->json();
        $this->assertStringNotContainsString('Secret-A-text', json_encode($tickets), "Store B's tickets list Store A's ticket");
    }

    public function test_an_employee_cannot_open_payslips_or_other_peoples_payroll(): void
    {
        $employeeUser = User::factory()->create(['role' => 'employee', 'sub_role' => 'checker', 'is_active' => true]);
        $other = Employee::create(['employee_id' => 'EMP-0009', 'first_name' => 'Ana', 'last_name' => 'Reyes', 'position' => 'Guard', 'daily_rate' => 700, 'date_hired' => '2026-01-05', 'is_active' => true]);
        $payroll = Payroll::create(['employee_id' => $other->id, 'period_start' => '2026-10-01', 'period_end' => '2026-10-14', 'gross_pay' => 5000, 'net_pay' => 4500, 'status' => 'unpaid', 'prepared_by' => $employeeUser->id]);

        Sanctum::actingAs($employeeUser);
        $this->getJson("/api/payrolls/{$payroll->id}/payslip")->assertForbidden();
        $this->getJson('/api/payroll-records')->assertForbidden();
        $this->getJson('/api/payrolls')->assertForbidden();
        $this->postJson('/api/payrolls/mark-paid', ['ids' => [$payroll->id]])->assertForbidden();
        $this->assertSame('unpaid', $payroll->fresh()->status);
    }
}
