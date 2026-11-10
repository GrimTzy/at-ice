<?php

namespace Tests\Feature;

use App\Models\Delivery;
use App\Models\DeliveryNotification;
use App\Models\DriverStoreAssignment;
use App\Models\RouteStop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Routes A to D (client, 2026-11-10): each route has a regular Driver and its
// stores in stop order. Contract deliveries go to the route's Driver and the
// Driver View lists the stops in route order.
class RouteAssignmentTest extends TestCase
{
    use RefreshDatabase;
    use RouteFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRoutes();
        Sanctum::actingAs($this->admin);
    }

    public function test_an_approved_contract_gives_route_stores_to_the_routes_driver(): void
    {
        $ana = $this->driver('Ana');
        $ben = $this->driver('Ben');
        $onRoute = $this->store('Alfamart One');
        $offRoute = $this->store('Corner Store');
        $route = $this->route('A', $ana, [$onRoute]);
        DriverStoreAssignment::create(['driver_id' => $ben->id, 'store_id' => $offRoute->id, 'effective_from' => '2026-11-01']);
        // a Driver Stores assignment never beats the route
        DriverStoreAssignment::create(['driver_id' => $ben->id, 'store_id' => $onRoute->id, 'effective_from' => '2026-11-01']);

        $contract = $this->contract([$onRoute, $offRoute], ['bags' => 20], ['wed', 'thu'], '2026-11-11', '2026-11-12');
        $this->postJson("/api/delivery-contracts/{$contract->id}/approve")->assertOk()->assertJsonPath('created', 4);

        $this->assertSame(2, Delivery::where('customer_id', $onRoute->id)->where('driver_id', $ana->id)->where('route_id', $route->id)->count());
        $this->assertSame(2, Delivery::where('customer_id', $offRoute->id)->where('driver_id', $ben->id)->whereNull('route_id')->count());
    }

    public function test_the_driver_view_lists_todays_stops_in_route_order(): void
    {
        $ana = $this->driver('Ana');
        // stop 1 is the farthest from the plant: route order wins over distance
        $first = $this->store('Stop one', 14.8800, 121.0600);
        $second = $this->store('Stop two', 14.7600, 121.0740);
        $third = $this->store('Stop three', 14.8000, 121.0700);
        $offRoute = $this->store('Off route', 14.7510, 121.0740);
        $route = $this->route('A', $ana, [$first, $second, $third]);
        $ids = [];
        foreach ([$third, $offRoute, $first, $second] as $s) {
            $ids[$s->id] = $this->delivery($ana, $s, ['bags' => 5], route: $s === $offRoute ? null : $route)->id;
        }

        Sanctum::actingAs($ana);
        $rows = collect($this->getJson('/api/driver/deliveries')->assertOk()->json('deliveries'))->where('route_today', true)->values();

        $this->assertSame([$ids[$first->id], $ids[$second->id], $ids[$third->id], $ids[$offRoute->id]], $rows->pluck('id')->all());
        $this->assertSame(['A', 'A', 'A', null], $rows->pluck('route_code')->all());
        $this->assertSame([1, 2, 3, null], $rows->pluck('stop_order')->all());
    }

    public function test_a_new_regular_driver_takes_the_upcoming_stops_and_the_store_is_told(): void
    {
        $ana = $this->driver('Ana');
        $ben = $this->driver('Ben');
        $customerUser = \App\Models\User::create(['name' => 'Alfa', 'email' => 'alfa@atice.invalid', 'password' => 'secret-password', 'role' => 'customer']);
        $store = $this->store('Alfamart One');
        $store->update(['user_id' => $customerUser->id]);
        $route = $this->route('A', $ana, [$store]);
        $tomorrow = $this->delivery($ana, $store, date: '2026-11-12', route: $route);
        $done = $this->delivery($ana, $store, status: 'delivered', route: $route);

        $res = $this->putJson("/api/routes/{$route->id}", ['driver_id' => $ben->id])->assertOk();

        $this->assertSame(1, $res->json('deliveries_changed'));
        $this->assertSame($ben->id, $tomorrow->fresh()->driver_id);
        $this->assertSame($ana->id, $done->fresh()->driver_id);
        $this->assertTrue(DeliveryNotification::where('delivery_id', $tomorrow->id)->where('kind', 'driver_changed')->where('channel', 'portal')->exists());
    }

    public function test_a_driver_is_the_regular_driver_of_one_route_only(): void
    {
        $ana = $this->driver('Ana');
        $this->route('A', $ana, []);
        $b = $this->route('B', null, []);

        $this->putJson("/api/routes/{$b->id}", ['driver_id' => $ana->id])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This driver already has Route A.');
        $this->postJson('/api/routes', ['name' => 'Route C', 'driver_id' => $ana->id])->assertUnprocessable();
        $this->postJson('/api/routes', ['name' => 'Route C'])->assertCreated()->assertJsonPath('code', 'C');
    }

    public function test_saving_the_stops_moves_a_store_from_another_route_and_its_deliveries_follow(): void
    {
        $ana = $this->driver('Ana');
        $ben = $this->driver('Ben');
        $s1 = $this->store('Store 1');
        $s2 = $this->store('Store 2');
        $a = $this->route('A', $ana, [$s1, $s2]);
        $b = $this->route('B', $ben, []);
        $upcoming = $this->delivery($ana, $s2, date: '2026-11-12', route: $a);

        $res = $this->putJson("/api/routes/{$b->id}/stops", ['stops' => [
            ['customer_id' => $s2->id, 'stop_order' => 1, 'stop_group' => null],
        ]])->assertOk();

        $this->assertSame('A', $res->json('moved_from_other_routes.0.from_route'));
        $this->assertSame($b->id, RouteStop::where('customer_id', $s2->id)->value('route_id'));
        $this->assertSame(1, $a->stops()->count());
        $this->assertSame($ben->id, $upcoming->fresh()->driver_id);
        $this->assertSame($b->id, $upcoming->fresh()->route_id);

        // taking the store off every route gives it back to Driver Stores (none here)
        $this->putJson("/api/routes/{$b->id}/stops", ['stops' => []])->assertOk();
        $this->assertNull($upcoming->fresh()->driver_id);
        $this->assertNull($upcoming->fresh()->route_id);
    }

    public function test_routes_list_shows_stops_and_counts_tenants_of_one_stop(): void
    {
        $ana = $this->driver('Ana');
        $t1 = $this->store('Savory - SM City San Jose del Monte');
        $t2 = $this->store('Zagu - SM City San Jose del Monte');
        $alfa = $this->store('Alfamart One');
        $route = $this->route('D', $ana, []);
        RouteStop::create(['route_id' => $route->id, 'customer_id' => $t1->id, 'stop_order' => 1, 'stop_group' => 'SM City San Jose del Monte']);
        RouteStop::create(['route_id' => $route->id, 'customer_id' => $t2->id, 'stop_order' => 1, 'stop_group' => 'SM City San Jose del Monte']);
        RouteStop::create(['route_id' => $route->id, 'customer_id' => $alfa->id, 'stop_order' => 2]);

        $row = $this->getJson('/api/routes')->assertOk()->json('0');
        $this->assertSame('D', $row['code']);
        $this->assertSame('Ana', $row['driver']['name']);
        $this->assertSame(2, $row['stop_count']);
        $this->assertSame(3, $row['customer_count']);
    }

    public function test_only_admin_manages_routes(): void
    {
        Sanctum::actingAs($this->driver('Ana'));
        $this->getJson('/api/routes')->assertForbidden();
        $this->postJson('/api/routes', ['name' => 'Route A'])->assertForbidden();
    }
}
