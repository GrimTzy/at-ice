<?php

namespace Tests\Feature;

use App\Models\DeliveryNotification;
use App\Models\Employee;
use App\Models\LiveEvent;
use App\Models\User;
use App\Support\DeliveryDispatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// The reserve driver (owner, 2026-11-11): Larry, a Driver account marked as
// reserve, takes an absent Driver's whole route if it fits the truck limits.
// If he cannot, the stops wait (no driver), Admin is alerted, and each store
// gets a portal notice.
class ReserveDriverTest extends TestCase
{
    use RefreshDatabase;
    use RouteFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRoutes();
        Sanctum::actingAs($this->admin);
    }

    private function portalStore(string $name)
    {
        $user = User::create(['name' => $name, 'email' => \Illuminate\Support\Str::slug($name).'@atice.invalid', 'password' => 'secret-password', 'role' => 'customer']);
        $store = $this->store($name);
        $store->update(['user_id' => $user->id]);

        return $store;
    }

    public function test_an_absent_drivers_whole_route_goes_to_the_reserve_driver(): void
    {
        $rodel = $this->driver('Rodel', null); // not timed in
        $ana = $this->driver('Ana');
        $larry = $this->reserve();
        $s1 = $this->portalStore('Alfamart One');
        $s2 = $this->portalStore('Alfamart Two');
        $route = $this->route('B', $rodel, [$s1, $s2]);
        $d1 = $this->delivery($rodel, $s1, route: $route);
        $d2 = $this->delivery($rodel, $s2, route: $route);
        $this->absent($rodel);

        DeliveryDispatch::reassign($rodel->id, '2026-11-11');

        // all of it to Larry, none to Ana (the FIFO round-robin is not used for routes)
        foreach ([$d1, $d2] as $d) {
            $this->assertSame($larry->id, $d->fresh()->driver_id);
            $this->assertSame('pending', $d->fresh()->status);
            $this->assertSame($rodel->id, $d->fresh()->reassigned_from_driver_id);
            $this->assertTrue(DeliveryNotification::where('delivery_id', $d->id)->where('kind', 'driver_changed')->where('channel', 'portal')->exists());
        }
        $this->assertTrue(LiveEvent::where('message', 'like', 'Route B: reserve driver Larry takes 2 stops%')->exists());
    }

    public function test_when_the_reserve_has_not_timed_in_the_stops_wait_and_admin_is_alerted(): void
    {
        $rodel = $this->driver('Rodel', null);
        $this->driver('Ana'); // free, but a route never goes to another regular Driver
        $larry = $this->reserve('Larry', null);
        $s1 = $this->portalStore('Alfamart One');
        $route = $this->route('B', $rodel, [$s1]);
        $d = $this->delivery($rodel, $s1, route: $route);
        $this->absent($rodel);

        DeliveryDispatch::reassign($rodel->id, '2026-11-11');

        $d->refresh();
        $this->assertSame('queued', $d->status);
        $this->assertNull($d->driver_id);
        $this->assertTrue(DeliveryNotification::where('delivery_id', $d->id)->where('kind', 'waiting')->exists());
        $alert = LiveEvent::where('message', 'like', 'Route B: Rodel is absent%')->first();
        $this->assertNotNull($alert);
        $this->assertSame([], $alert->roles); // Admin

        // the FIFO queue does not pick it up
        DeliveryDispatch::assignQueued();
        $this->assertNull($d->fresh()->driver_id);

        // Larry times in later: the Waiting route goes to him
        \App\Models\Attendance::create(['employee_id' => Employee::where('user_id', $larry->id)->value('id'), 'date' => '2026-11-11', 'time_in' => '09:00:00', 'status' => 'present']);
        DeliveryDispatch::assignWaitingToReserve();
        $this->assertSame($larry->id, $d->fresh()->driver_id);
        $this->assertSame('pending', $d->fresh()->status);
    }

    public function test_the_reserve_takes_one_route_a_day(): void
    {
        $rodel = $this->driver('Rodel', null);
        $dan = $this->driver('Dan', null);
        $larry = $this->reserve();
        $s1 = $this->store('Store B1');
        $s2 = $this->store('Store C1');
        $b = $this->route('B', $rodel, [$s1]);
        $c = $this->route('C', $dan, [$s2]);
        $db = $this->delivery($rodel, $s1, route: $b);
        $dc = $this->delivery($dan, $s2, route: $c);
        $this->absent($rodel);
        $this->absent($dan);

        DeliveryDispatch::reassign($rodel->id, '2026-11-11');
        DeliveryDispatch::reassign($dan->id, '2026-11-11');

        $this->assertSame($larry->id, $db->fresh()->driver_id);
        $this->assertSame('queued', $dc->fresh()->status);
    }

    public function test_the_reserve_does_not_take_a_route_that_goes_over_the_truck_load(): void
    {
        $rodel = $this->driver('Rodel', null);
        $larry = $this->reserve();
        $s1 = $this->store('Store 1');
        $s2 = $this->store('Store 2');
        $route = $this->route('B', $rodel, [$s1, $s2]);
        $d1 = $this->delivery($rodel, $s1, ['big_sack' => 100], route: $route);
        $d2 = $this->delivery($rodel, $s2, ['big_sack' => 60], route: $route); // 160 > 150
        $this->absent($rodel);

        DeliveryDispatch::reassign($rodel->id, '2026-11-11');

        $this->assertSame('queued', $d1->fresh()->status);
        $this->assertSame('queued', $d2->fresh()->status);
        $this->assertNotSame($larry->id, $d1->fresh()->driver_id);
    }

    public function test_the_reserve_never_gets_fifo_deliveries(): void
    {
        $ana = $this->driver('Ana');
        $larry = $this->reserve();

        $this->assertSame([$ana->id], DeliveryDispatch::availableDrivers());
        $this->assertNotContains($larry->id, DeliveryDispatch::availableDrivers());
    }

    public function test_the_reserve_uses_the_driver_view_and_a_maintenance_employee_may_not(): void
    {
        $larry = $this->reserve();
        $store = $this->store('Store 1', 14.8, 121.07);
        $this->delivery($larry, $store);
        $mario = User::create(['name' => 'Mario', 'email' => 'mario@atice.cloud', 'password' => 'secret-password', 'role' => 'employee', 'sub_role' => 'maintenance']);
        $this->employee($mario, 'Maintenance', '06:30:00');

        Sanctum::actingAs($larry);
        $this->getJson('/api/driver/deliveries')->assertOk()->assertJsonCount(1, 'deliveries');

        // the reserve is a Driver account now, not a Maintenance employee
        Sanctum::actingAs($mario);
        $this->getJson('/api/driver/deliveries')->assertForbidden();
    }

    public function test_the_reserve_logs_in_as_a_driver(): void
    {
        $larry = $this->reserve();
        $larry->update(['password' => 'Secret-password1']);

        $this->postJson('/api/login', ['email' => 'larry@atice.cloud', 'password' => 'Secret-password1'])
            ->assertOk()
            ->assertJsonPath('role', 'driver');

        Sanctum::actingAs($larry->fresh());
        $this->getJson('/api/me')->assertOk()->assertJsonPath('is_reserve_driver', true);
    }

    public function test_admin_marks_a_driver_without_a_route_as_reserve(): void
    {
        $ana = $this->driver('Ana');
        $ben = $this->driver('Ben');
        $route = $this->route('A', $ana, [$this->store('Store 1')]);

        // a Driver with a route cannot be a reserve
        $this->putJson("/api/drivers/{$ana->id}/reserve", ['is_reserve' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('is_reserve');
        $this->putJson("/api/drivers/{$ben->id}/reserve", [])->assertUnprocessable();

        $this->putJson("/api/drivers/{$ben->id}/reserve", ['is_reserve' => true])->assertOk();
        $this->assertTrue($ben->fresh()->isReserveDriver());

        // a reserve cannot be given a route
        $this->putJson("/api/routes/{$route->id}", ['driver_id' => $ben->id])
            ->assertUnprocessable()->assertJsonValidationErrors('driver_id');

        // only Drivers can be marked
        $maint = User::create(['name' => 'Mario', 'email' => 'mario@atice.cloud', 'password' => 'secret-password', 'role' => 'employee', 'sub_role' => 'maintenance']);
        $this->putJson("/api/drivers/{$maint->id}/reserve", ['is_reserve' => true])->assertNotFound();

        $this->putJson("/api/drivers/{$ben->id}/reserve", ['is_reserve' => false])->assertOk();
        $this->assertFalse($ben->fresh()->isReserveDriver());
        $this->putJson("/api/routes/{$route->id}", ['driver_id' => $ben->id])->assertOk();

        // Admin only
        Sanctum::actingAs(User::create(['name' => 'HR', 'email' => 'hr@atice.cloud', 'password' => 'secret-password', 'role' => 'hr']));
        $this->putJson("/api/drivers/{$ana->id}/reserve", ['is_reserve' => true])->assertForbidden();
    }

    public function test_the_board_and_capacity_list_the_reserve(): void
    {
        $this->driver('Ana');
        $larry = $this->reserve();

        $board = collect($this->getJson('/api/deliveries/board?date=2026-11-11')->assertOk()->json('drivers'))->keyBy('id');
        $this->assertTrue($board[$larry->id]['is_reserve']);
        $this->assertSame([], $board[$larry->id]['truck_stock']); // rolling stock is off

        $cap = collect($this->getJson('/api/routes/capacity?date=2026-11-11')->assertOk()->json('drivers'))->keyBy('id');
        $this->assertTrue($cap[$larry->id]['is_reserve']);
    }
}
