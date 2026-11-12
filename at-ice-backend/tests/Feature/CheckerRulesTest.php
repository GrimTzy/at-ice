<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\Employee;
use App\Models\IceLoading;
use App\Models\Product;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Owner change requests of 2026-10-16: one ice loading count per delivery,
// Checkers save nothing before their kiosk Time In, minimum and maximum
// stock are Admin only, and a stock-out request needs a purpose.
class CheckerRulesTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private function checker(bool $timedIn = true): User
    {
        $this->n++;
        $user = User::create(['name' => "Checker {$this->n}", 'email' => "checker{$this->n}@atice.cloud", 'password' => 'secret-password', 'role' => 'employee', 'sub_role' => 'checker']);
        $emp = Employee::create([
            'user_id' => $user->id, 'employee_id' => 'EMP-00'.(10 + $this->n), 'first_name' => 'Check', 'last_name' => "Er{$this->n}",
            'position' => 'Checker', 'daily_rate' => 600, 'date_hired' => '2026-01-05', 'is_active' => true,
        ]);
        if ($timedIn) {
            Attendance::create(['employee_id' => $emp->id, 'date' => now()->toDateString(), 'time_in' => now()->subMinutes(5)->format('H:i:s'), 'status' => 'present']);
        }

        return $user;
    }

    private function admin(): User
    {
        return User::create(['name' => 'Admin', 'email' => 'admin@atice.cloud', 'password' => 'secret-password', 'role' => 'admin']);
    }

    private function delivery(): Delivery
    {
        $this->n++;
        $driver = User::create(['name' => 'Driver', 'email' => "driver{$this->n}@atice.cloud", 'password' => 'secret-password', 'role' => 'driver']);
        $customer = Customer::create(['name' => 'Alfamart', 'customer_code' => "C-{$this->n}", 'branch_code' => "B-{$this->n}", 'invoice_code' => 'ALF', 'is_active' => true]);

        return Delivery::create([
            'delivery_no' => "DEL-{$this->n}", 'driver_id' => $driver->id, 'customer_id' => $customer->id,
            'status' => 'pending', 'scheduled_date' => now()->toDateString(), 'prepared_by' => $driver->id,
        ]);
    }

    private function loading(Delivery $delivery, string $type = 'bags'): array
    {
        return ['delivery_id' => $delivery->id, 'loaded_at' => now()->format('Y-m-d H:i:s'), 'items' => [['product_type' => $type, 'quantity' => 10]]];
    }

    public function test_a_delivery_is_counted_only_once_even_by_the_other_checker(): void
    {
        $delivery = $this->delivery();
        $first = $this->checker();
        $second = $this->checker();

        Sanctum::actingAs($first);
        $this->postJson('/api/ice-loadings', $this->loading($delivery))->assertCreated();

        // The same checker again, and the other checker with another product type
        $this->postJson('/api/ice-loadings', $this->loading($delivery))->assertStatus(409)->assertJson(['already_loaded' => true]);
        Sanctum::actingAs($second);
        $this->postJson('/api/ice-loadings', $this->loading($delivery, 'big_sack'))
            ->assertStatus(409)
            ->assertJson(['already_loaded' => true]);

        $this->assertSame(1, IceLoading::where('delivery_id', $delivery->id)->count());

        // The form lists it as already counted, with who counted it
        $this->getJson('/api/ice-loadings/deliveries')->assertOk()
            ->assertJsonFragment(['id' => $delivery->id, 'loaded' => true, 'loaded_by' => $first->name]);
    }

    public function test_the_database_refuses_a_second_row_for_the_same_delivery_and_type(): void
    {
        $delivery = $this->delivery();
        $checker = $this->checker();
        $row = ['delivery_id' => $delivery->id, 'product_type' => 'bags', 'checker_id' => $checker->id, 'quantity_loaded' => 5, 'loaded_at' => now()];
        IceLoading::create($row);

        $this->expectException(UniqueConstraintViolationException::class);
        IceLoading::create($row);
    }

    public function test_a_checker_saves_nothing_before_the_kiosk_time_in(): void
    {
        $delivery = $this->delivery();
        $checker = $this->checker(timedIn: false);
        Sanctum::actingAs($checker);

        $this->getJson('/api/my-time-in')->assertOk()->assertJson(['timed_in' => false]);
        // Reading still works
        $this->getJson('/api/ice-loadings')->assertOk();
        $this->getJson('/api/products')->assertOk();

        $this->postJson('/api/ice-loadings', $this->loading($delivery))->assertForbidden()->assertJson(['needs_time_in' => true]);
        $this->postJson('/api/products', ['name' => 'Plastic Bags'])->assertForbidden();
        $this->postJson('/api/suppliers', ['name' => 'Supplier'])->assertForbidden();
        $this->assertSame(0, IceLoading::count());

        // Timed in: saving works
        $att = Attendance::create(['employee_id' => $checker->employee->id, 'date' => now()->toDateString(), 'time_in' => now()->subMinutes(5)->format('H:i:s'), 'status' => 'present']);
        $this->getJson('/api/my-time-in')->assertJson(['timed_in' => true]);
        $this->postJson('/api/ice-loadings', $this->loading($delivery))->assertCreated();

        // After Time Out the shift is over: saving is closed again
        $att->update(['time_out' => now()->format('H:i:s')]);
        $this->postJson('/api/products', ['name' => 'Sacks'])->assertForbidden();
    }

    public function test_admin_is_not_blocked_by_the_time_in_rule(): void
    {
        Sanctum::actingAs($this->admin());
        $this->postJson('/api/products', ['name' => 'Plastic Bags', 'minimum_stock' => 5, 'maximum_stock' => 50])->assertCreated();
    }

    public function test_only_admin_sets_minimum_and_maximum_stock(): void
    {
        Sanctum::actingAs($this->checker());

        // A Checker's new item gets the defaults; sending limits is refused
        $this->postJson('/api/products', ['name' => 'Sacks', 'minimum_stock' => 5])->assertForbidden();
        $id = $this->postJson('/api/products', ['name' => 'Sacks'])->assertCreated()->json('id');
        $product = Product::find($id);
        $this->assertSame(10, (int) $product->minimum_stock);
        $this->assertSame(1000, (int) $product->maximum_stock);
        // it waits for the Admin, who is told in the notice
        $this->assertNull($product->limits_set_at);
        $this->assertSame('Item Sacks added by Checker 1. Its limits still need to be set', \App\Models\LiveEvent::latest('id')->value('message'));

        // An edit may send the limits unchanged, never changed
        $this->putJson("/api/products/{$id}", ['name' => 'Sacks 50kg', 'minimum_stock' => 10, 'maximum_stock' => 1000])->assertOk();
        $this->assertNull($product->fresh()->limits_set_at); // a Checker's edit does not set the limits
        $this->putJson("/api/products/{$id}", ['minimum_stock' => 1])->assertForbidden();
        $this->putJson("/api/products/{$id}", ['maximum_stock' => 9999])->assertForbidden();
        $this->assertSame(10, (int) $product->fresh()->minimum_stock);

        // HR cannot reach the products API at all
        Sanctum::actingAs(User::create(['name' => 'HR', 'email' => 'hr@atice.cloud', 'password' => 'secret-password', 'role' => 'hr']));
        $this->putJson("/api/products/{$id}", ['minimum_stock' => 1])->assertForbidden();

        Sanctum::actingAs($this->admin());
        $this->putJson("/api/products/{$id}", ['minimum_stock' => 20, 'maximum_stock' => 200])->assertOk();
        $this->assertSame(20, (int) $product->fresh()->minimum_stock);
        $this->assertSame(200, (int) $product->fresh()->maximum_stock);
        $this->assertNotNull($product->fresh()->limits_set_at); // "Needs limits" is cleared
        // the Checker's notice says what the Admin set, not just "updated"
        $this->assertSame('Item Sacks 50kg limits set to min 20, max 200 by Admin', \App\Models\LiveEvent::latest('id')->value('message'));
        $this->putJson("/api/products/{$id}", ['minimum_stock' => 90, 'maximum_stock' => 50])->assertUnprocessable(); // max below min
        // digits: the minimum has at most 2, the maximum at most 5
        $this->putJson("/api/products/{$id}", ['minimum_stock' => 100, 'maximum_stock' => 5000])->assertUnprocessable()->assertJsonValidationErrors('minimum_stock');
        $this->putJson("/api/products/{$id}", ['minimum_stock' => 5, 'maximum_stock' => 100000])->assertUnprocessable()->assertJsonValidationErrors('maximum_stock');
        $this->putJson("/api/products/{$id}", ['minimum_stock' => 99, 'maximum_stock' => 99999])->assertOk();

        // an old item over a cap does not block a Checker's edit (the limits they send are ignored)
        $product->forceFill(['minimum_stock' => 150])->save();
        Sanctum::actingAs($this->checker());
        $this->putJson("/api/products/{$id}", ['name' => 'Sacks 25kg', 'minimum_stock' => 150, 'maximum_stock' => 99999])->assertOk();
        $this->assertSame(150, (int) $product->fresh()->minimum_stock);
    }

    public function test_a_stock_out_request_needs_a_purpose_and_projects_carry_their_description(): void
    {
        $maint = $this->timedIn(User::create(['name' => 'Maint', 'email' => 'maint@atice.cloud', 'password' => 'secret-password', 'role' => 'employee', 'sub_role' => 'maintenance']));
        $product = Product::create(['product_code' => 'SUP-0001', 'name' => 'Brake Pad', 'unit' => 'pcs', 'current_stock' => 10, 'minimum_stock' => 1]);
        $project = Project::create(['name' => 'Truck 2 brakes', 'description' => 'Replace the brake pads of truck 2', 'status' => 'working', 'created_by' => $maint->id]);

        Sanctum::actingAs($maint);
        $this->getJson('/api/project-options')->assertOk()
            ->assertJsonFragment(['id' => $project->id, 'description' => 'Replace the brake pads of truck 2']);

        $this->postJson('/api/stock-out-requests', ['product_id' => $product->id, 'quantity' => 1, 'purpose' => '   ', 'project_id' => $project->id])
            ->assertUnprocessable()->assertJsonValidationErrors('purpose');
        $this->postJson('/api/stock-out-requests', ['product_id' => $product->id, 'quantity' => 1, 'project_id' => null])
            ->assertUnprocessable()->assertJsonValidationErrors('purpose');
        $this->postJson('/api/stock-out-requests', ['product_id' => $product->id, 'quantity' => 1, 'purpose' => 'Replace the brake pads of truck 2', 'project_id' => $project->id])
            ->assertCreated();
    }
}
