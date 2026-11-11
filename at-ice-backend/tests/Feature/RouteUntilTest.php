<?php

namespace Tests\Feature;

use App\Models\DeliveryRoute;
use App\Models\LiveEvent;
use App\Support\DeliveryDispatch;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// "No end date" with an optional Until (owner, 2026-11-12, from Kevin's
// 2a3e473): empty Until keeps the regular Driver until changed; with Until
// the Driver is the regular one until that day, then the route has none and
// its stops go to the reserve driver or Waiting. Admin is alerted once, 3 days
// before. "Today" is Wednesday 2026-11-11 (RouteFixtures).
class RouteUntilTest extends TestCase
{
    use RefreshDatabase;
    use RouteFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRoutes();
        Sanctum::actingAs($this->admin);
    }

    public function test_a_regular_driver_until_a_date_then_the_route_has_none(): void
    {
        $rodel = $this->driver('Rodel');
        $ana = $this->driver('Ana');
        $store = $this->store('Store 1');
        $route = $this->route('A', $rodel, [$store]);
        $thisWeek = $this->delivery($rodel, $store, date: '2026-11-13', route: $route);
        $nextWeek = $this->delivery($rodel, $store, date: '2026-11-18', route: $route);
        $afterUntil = $this->delivery($rodel, $store, date: '2026-11-23', route: $route);

        $res = $this->putJson("/api/routes/{$route->id}", ['driver_id' => $ana->id, 'driver_from' => '2026-11-16', 'driver_to' => '2026-11-20'])->assertOk();
        $this->assertSame('2026-11-16', $res->json('route.driver_from'));
        $this->assertSame('2026-11-20', $res->json('route.driver_to'));
        $this->assertStringContainsString('Ana is the regular driver from Nov 16 until Nov 20 (Rodel until then).', $res->json('message'));
        $this->assertStringContainsString('alerted 3 days before', $res->json('message'));

        $this->assertSame($rodel->id, $thisWeek->fresh()->driver_id);
        $this->assertSame($ana->id, $nextWeek->fresh()->driver_id);
        $this->assertNull($afterUntil->fresh()->driver_id); // no regular driver after Until
        $this->assertNull($route->fresh()->driverOn('2026-11-21'));

        // empty Until: the regular driver until changed (as before)
        $res = $this->putJson("/api/routes/{$route->id}", ['driver_id' => $ana->id, 'driver_from' => '2026-11-16', 'driver_to' => null])->assertOk();
        $this->assertNull($res->json('route.driver_to'));
        $this->assertSame($ana->id, $afterUntil->fresh()->driver_id);
    }

    public function test_until_must_be_on_or_after_from(): void
    {
        $ana = $this->driver('Ana');
        $route = $this->route('A', null, [$this->store('Store 1')]);

        $this->putJson("/api/routes/{$route->id}", ['driver_id' => $ana->id, 'driver_from' => '2026-11-16', 'driver_to' => '2026-11-15'])
            ->assertUnprocessable()->assertJsonValidationErrors('driver_to')->assertJsonPath('message', 'Until must be on or after From.');
        $this->putJson("/api/routes/{$route->id}", ['driver_id' => $ana->id, 'driver_to' => '2026-11-10'])
            ->assertUnprocessable()->assertJsonValidationErrors('driver_to');
        // From and Until on the same day is fine
        $this->putJson("/api/routes/{$route->id}", ['driver_id' => $ana->id, 'driver_from' => '2026-11-16', 'driver_to' => '2026-11-16'])->assertOk();
    }

    public function test_admin_is_alerted_once_three_days_before_until(): void
    {
        $ana = $this->driver('Ana');
        $route = $this->route('A', null, [$this->store('Store 1')]);
        $this->putJson("/api/routes/{$route->id}", ['driver_id' => $ana->id, 'driver_to' => '2026-11-20'])->assertOk();

        $this->assertSame(0, DeliveryDispatch::alertEndingRegularDrivers()); // Nov 11: too early

        Carbon::setTestNow(Carbon::parse('2026-11-17 08:00', 'Asia/Manila'));
        $this->assertSame(1, DeliveryDispatch::alertEndingRegularDrivers());
        $alert = LiveEvent::where('message', 'like', 'Route A: Ana is the regular driver until Nov 20%')->sole();
        $this->assertStringContainsString('(in 3 days)', $alert->message);
        $this->assertSame([], $alert->roles); // Admin

        // once only
        Carbon::setTestNow(Carbon::parse('2026-11-18 08:00', 'Asia/Manila'));
        $this->assertSame(0, DeliveryDispatch::alertEndingRegularDrivers());

        // a new Until is alerted again
        $this->putJson("/api/routes/{$route->id}", ['driver_id' => $ana->id, 'driver_to' => '2026-11-19'])->assertOk();
        $this->assertSame(1, DeliveryDispatch::alertEndingRegularDrivers());
    }

    public function test_after_until_the_stops_go_to_the_reserve_driver(): void
    {
        $rodel = $this->driver('Rodel');
        $larry = $this->reserve();
        $store = $this->store('Store 1');
        $route = $this->route('A', $rodel, [$store]);
        // Rodel's Until was yesterday
        $route->update(['driver_to' => '2026-11-10']);
        $today = $this->delivery(null, $store, route: $route);

        $this->assertSame(1, DeliveryDispatch::endRegularDrivers());
        $this->assertNull($route->fresh()->driver_id);
        $this->assertNull($route->fresh()->driver_to);

        DeliveryDispatch::queueDriverlessRouteStops();
        $this->assertSame($larry->id, $today->fresh()->driver_id);
        $this->assertSame('pending', $today->fresh()->status);
    }

    public function test_after_until_with_no_reserve_the_stops_wait_and_admin_is_told(): void
    {
        $rodel = $this->driver('Rodel');
        $store = $this->store('Store 1');
        $route = $this->route('A', $rodel, [$store]);
        $route->update(['driver_to' => '2026-11-10']);
        $today = $this->delivery(null, $store, route: $route);

        DeliveryDispatch::endRegularDrivers();
        DeliveryDispatch::queueDriverlessRouteStops();
        DeliveryDispatch::queueDriverlessRouteStops(); // told once

        $this->assertSame('queued', $today->fresh()->status);
        $this->assertNull($today->fresh()->driver_id);
        $this->assertSame(1, LiveEvent::where('message', 'like', 'Route A has no driver today%')->count());
        $this->getJson('/api/deliveries/waiting')->assertOk()->assertJsonPath('0.id', $today->id);
    }

    public function test_a_driver_whose_until_ends_before_may_take_another_route(): void
    {
        $rodel = $this->driver('Rodel');
        $a = $this->route('A', null, [$this->store('Store 1')]);
        $b = $this->route('B', null, [$this->store('Store 2')]);
        $this->putJson("/api/routes/{$a->id}", ['driver_id' => $rodel->id, 'driver_to' => '2026-11-20'])->assertOk();

        $this->putJson("/api/routes/{$b->id}", ['driver_id' => $rodel->id, 'driver_from' => '2026-11-18'])
            ->assertUnprocessable()->assertJsonPath('message', 'This driver already has Route A.');
        $this->putJson("/api/routes/{$b->id}", ['driver_id' => $rodel->id, 'driver_from' => '2026-11-21'])->assertOk();
        $this->assertSame($rodel->id, DeliveryRoute::find($b->id)->driverOn('2026-11-21'));
        $this->assertNull(DeliveryRoute::find($b->id)->driverOn('2026-11-20'));
    }
}
