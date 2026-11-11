<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\DriverStoreAssignment;
use App\Models\StoreRoute;
use App\Models\User;
use App\Support\DriverAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StoreRouteTest extends TestCase
{
    use RefreshDatabase;

    private function store(string $code): Customer
    {
        return Customer::create(['name' => "Store $code", 'customer_code' => "CUST-$code", 'branch_code' => "$code-SJDM-001", 'invoice_code' => $code, 'is_active' => true]);
    }

    public function test_admin_makes_a_route_and_a_store_moves_between_routes(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $a = $this->store('AAA');
        $b = $this->store('BBB');

        $routeA = $this->postJson('/api/store-routes', ['name' => 'Route A', 'store_ids' => [$a->id, $b->id]])->assertCreated()->json();
        $this->assertCount(2, $routeA['stores']);

        $routeB = $this->postJson('/api/store-routes', ['name' => 'Route B', 'store_ids' => [$b->id]])->assertCreated()->json();
        $this->assertSame($routeB['id'], $b->fresh()->store_route_id);

        $list = $this->getJson('/api/store-routes')->assertOk()->json();
        $this->assertCount(1, collect($list)->firstWhere('name', 'Route A')['stores']);

        $this->putJson("/api/store-routes/{$routeA['id']}", ['store_ids' => []])->assertOk();
        $this->assertNull($a->fresh()->store_route_id);

        $this->postJson('/api/store-routes', ['name' => 'Route A'])->assertStatus(422);
        $this->deleteJson("/api/store-routes/{$routeB['id']}")->assertOk();
        $this->assertNull($b->fresh()->store_route_id);
    }

    public function test_a_driver_cannot_manage_routes(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'driver']));
        $this->getJson('/api/store-routes')->assertForbidden();
        $this->postJson('/api/store-routes', ['name' => 'Route X'])->assertForbidden();
    }
    public function test_a_route_handed_to_a_driver_makes_its_stores_follow_the_driver(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $driver = User::factory()->create(['role' => 'driver']);
        $a = $this->store('AAA');
        $b = $this->store('BBB');
        $today = DriverAvailability::today();

        $route = $this->postJson('/api/store-routes', ['name' => 'Route A', 'store_ids' => [$a->id]])->json();
        $this->postJson('/api/driver-route-assignments', ['route_id' => $route['id'], 'driver_id' => $driver->id, 'effective_from' => $today])->assertCreated();
        $this->assertSame($driver->id, DriverStoreAssignment::driverFor($a->id, $today));

        // a store added later follows the route's driver; one taken off stops following
        $this->putJson("/api/store-routes/{$route['id']}", ['store_ids' => [$b->id]])->assertOk();
        $this->assertSame($driver->id, DriverStoreAssignment::driverFor($b->id, $today));
        $this->assertNull(DriverStoreAssignment::driverFor($a->id, DriverAvailability::today()));

        $this->deleteJson("/api/driver-route-assignments/{$route['id']}")->assertOk();
        $this->assertNull(DriverStoreAssignment::driverFor($b->id, $today));
        $this->assertNull(StoreRoute::find($route['id'])->driver_id);
    }
    public function test_route_input_is_validated(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $driver = User::factory()->create(['role' => 'driver']);
        $inactive = Customer::create(['name' => 'Closed', 'customer_code' => 'CUST-CLO', 'branch_code' => 'CLO-1', 'invoice_code' => 'CLO', 'is_active' => false]);

        $this->postJson('/api/store-routes', ['name' => '   '])->assertStatus(422)->assertJsonValidationErrors('name');
        $this->postJson('/api/store-routes', ['name' => str_repeat('x', 61)])->assertStatus(422)->assertJsonValidationErrors('name');
        $this->postJson('/api/store-routes', ['name' => 'Route A'])->assertCreated();
        $this->postJson('/api/store-routes', ['name' => '  route a '])->assertStatus(422)->assertJsonValidationErrors('name');
        $this->postJson('/api/store-routes', ['name' => 'Route B', 'store_ids' => [$inactive->id]])->assertStatus(422)->assertJsonValidationErrors('store_ids.0');
        $this->postJson('/api/store-routes', ['name' => 'Route C', 'store_ids' => [99999]])->assertStatus(422);

        // a route handed to a driver needs an active, non-reserve driver and a real route
        $route = StoreRoute::first();
        $today = DriverAvailability::today();
        $this->putJson("/api/drivers/{$driver->id}/reserve", ['is_reserve' => true])->assertOk();
        $this->postJson('/api/driver-route-assignments', ['route_id' => $route->id, 'driver_id' => $driver->id, 'effective_from' => $today])->assertStatus(422)->assertJsonValidationErrors('driver_id');
        $this->putJson("/api/drivers/{$driver->id}/reserve", ['is_reserve' => false])->assertOk();
        $this->postJson('/api/driver-route-assignments', ['route_id' => 99999, 'driver_id' => $driver->id, 'effective_from' => $today])->assertStatus(422)->assertJsonValidationErrors('route_id');
        $this->postJson('/api/driver-route-assignments', ['route_id' => $route->id, 'driver_id' => $driver->id, 'effective_from' => '2020-01-01'])->assertStatus(422)->assertJsonValidationErrors('effective_from');
        $this->postJson('/api/driver-route-assignments', ['route_id' => $route->id, 'driver_id' => $driver->id, 'effective_from' => $today, 'effective_to' => '2020-01-01'])->assertStatus(422)->assertJsonValidationErrors('effective_to');

        // a driver with a route cannot be made a reserve
        $this->postJson('/api/driver-route-assignments', ['route_id' => $route->id, 'driver_id' => $driver->id, 'effective_from' => $today])->assertCreated();
        $this->putJson("/api/drivers/{$driver->id}/reserve", ['is_reserve' => true])->assertStatus(422);
        $this->putJson("/api/drivers/{$driver->id}/reserve", [])->assertStatus(422);
    }
}