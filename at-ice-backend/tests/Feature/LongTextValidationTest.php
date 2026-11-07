<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerPrice;
use App\Models\Delivery;
use App\Models\Employee;
use App\Models\Product;
use App\Models\User;
use App\Support\DriverAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Text longer than its database column is refused with a field message (422),
// never a database error (500). SQLite ignores VARCHAR lengths, so these tests
// check the validation rules; MySQL would fail with "Data too long" without them.
class LongTextValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@atice.cloud', 'password' => 'secret-password', 'role' => 'admin']);
    }

    private function employeePayload(array $overrides = []): array
    {
        return $overrides + [
            'first_name' => 'Carlo', 'last_name' => 'Jayco', 'position' => 'Checker', 'phone' => '09171234550',
            'street' => 'Quirino Highway', 'barangay' => 'Muzon', 'city' => 'San Jose del Monte', 'province' => 'Bulacan',
            'daily_rate' => 695, 'date_hired' => now()->subDay()->toDateString(), 'shift' => 'day', 'employment_type' => 'regular',
        ];
    }

    public function test_employee_names_longer_than_100_characters_are_refused(): void
    {
        Sanctum::actingAs(User::create(['name' => 'HR', 'email' => 'hr@atice.cloud', 'password' => 'secret-password', 'role' => 'hr']));

        $this->postJson('/api/employees', $this->employeePayload(['first_name' => str_repeat('A', 101), 'last_name' => str_repeat('B', 101)]))
            ->assertUnprocessable()->assertJsonValidationErrors(['first_name', 'last_name']);
        $this->assertSame(0, Employee::count());

        // 100 characters each still fit the login name and email (users.name, users.email)
        $id = $this->postJson('/api/employees', $this->employeePayload(['first_name' => str_repeat('A', 100), 'last_name' => str_repeat('B', 100)]))
            ->assertCreated()->json('employee.id');

        $this->putJson("/api/employees/{$id}", ['first_name' => str_repeat('C', 101)])
            ->assertUnprocessable()->assertJsonValidationErrors('first_name');
    }

    public function test_customer_notes_longer_than_1000_characters_are_refused(): void
    {
        Sanctum::actingAs($this->admin);
        $payload = [
            'name' => 'Starmall', 'email' => 'starmall@gmail.com', 'contact_number' => '09171234567', 'contact_person' => 'Ana',
            'street' => 'Quirino Highway', 'barangay' => 'Muzon', 'city' => 'San Jose del Monte', 'province' => 'Bulacan',
            'customer_type' => 'dealer', 'latitude' => 14.81, 'longitude' => 121.05,
        ];

        $this->postJson('/api/customers', $payload + ['notes' => str_repeat('n', 1001)])
            ->assertUnprocessable()->assertJsonValidationErrors('notes');
        $this->assertSame(0, Customer::count());

        $id = $this->postJson('/api/customers', $payload + ['notes' => str_repeat('n', 1000)])->assertCreated()->json('id');
        $this->putJson("/api/customers/{$id}", ['notes' => str_repeat('n', 1001)])
            ->assertUnprocessable()->assertJsonValidationErrors('notes');
    }

    public function test_delivery_notes_and_cancel_reason_longer_than_1000_characters_are_refused(): void
    {
        Sanctum::actingAs($this->admin);
        $driver = $this->timedIn(User::create(['name' => 'Driver', 'email' => 'driver@atice.cloud', 'password' => 'secret-password', 'role' => 'driver']));
        $store = Customer::create(['name' => 'Starmall', 'customer_code' => 'CUST-STA-001', 'branch_code' => 'STA-SJDM-001', 'is_active' => true]);
        CustomerPrice::create(['customer_id' => $store->id, 'product_type' => 'bags', 'unit_price' => 50]);

        $this->postJson('/api/deliveries', [
            'driver_id' => $driver->id, 'customer_id' => $store->id, 'delivery_type' => 'outbound',
            'scheduled_date' => DriverAvailability::today(), 'notes' => str_repeat('n', 1001),
            'items' => [['product_type' => 'bags', 'quantity_ordered' => 10]],
        ])->assertUnprocessable()->assertJsonValidationErrors('notes');
        $this->assertSame(0, Delivery::count());

        $d = Delivery::create(['delivery_no' => 'DEL-0001', 'driver_id' => $driver->id, 'customer_id' => $store->id, 'status' => 'pending', 'scheduled_date' => DriverAvailability::today(), 'prepared_by' => $this->admin->id]);
        $this->putJson("/api/deliveries/{$d->id}/cancel", ['reason' => str_repeat('r', 1001)])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->assertSame('pending', $d->fresh()->status);
    }

    public function test_stock_movement_reference_and_source_longer_than_255_characters_are_refused(): void
    {
        $checker = $this->timedIn(User::create(['name' => 'Checker', 'email' => 'checker@atice.cloud', 'password' => 'secret-password', 'role' => 'employee', 'sub_role' => 'checker']));
        $product = Product::create(['product_code' => 'SUP-001', 'name' => 'Gloves', 'unit' => 'pair', 'current_stock' => 10, 'minimum_stock' => 2, 'is_active' => true]);
        Sanctum::actingAs($checker);

        $this->postJson('/api/stock-movements', [
            'product_id' => $product->id, 'type' => 'stock_in', 'quantity' => 1,
            'reference_no' => str_repeat('R', 256), 'source' => str_repeat('S', 256), 'reason' => str_repeat('x', 1001),
        ])->assertUnprocessable()->assertJsonValidationErrors(['reference_no', 'source', 'reason']);

        $this->postJson('/api/stock-movements', [
            'product_id' => $product->id, 'type' => 'stock_in', 'quantity' => 1,
            'reference_no' => str_repeat('R', 255), 'source' => str_repeat('S', 255),
        ])->assertCreated();
    }

    public function test_pickup_log_notes_longer_than_1000_characters_are_refused(): void
    {
        $checker = $this->timedIn(User::create(['name' => 'Checker', 'email' => 'checker@atice.cloud', 'password' => 'secret-password', 'role' => 'employee', 'sub_role' => 'checker']));
        Sanctum::actingAs($checker);

        $this->postJson('/api/pickup-logs', [
            'company_name' => 'J-Maxine', 'product_type' => 'bags', 'quantity' => 5, 'pickup_date' => DriverAvailability::today(),
            'notes' => str_repeat('n', 1001),
        ])->assertUnprocessable()->assertJsonValidationErrors('notes');
    }

    public function test_login_with_an_overlong_email_or_password_is_a_validation_error(): void
    {
        $this->postJson('/api/login', ['email' => str_repeat('a', 250).'@gmail.com', 'password' => str_repeat('p', 256)])
            ->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
    }
}
