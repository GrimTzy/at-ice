<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Who drives a route (Driver Stores, owner 2026-11-11): the regular Driver
// ("No end date", from a start day) and a temporary cover ("One day", "One
// week") after which the regular Driver is back on his own; the code made by
// the system; and the Deliver by time kept when a Waiting delivery is
// rescheduled. "Today" is Wednesday 2026-11-11 (RouteFixtures).
class RouteDatesTest extends TestCase
{
    use RefreshDatabase;
    use RouteFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRoutes();
        Sanctum::actingAs($this->admin);
    }

    // Decision (owner, 2026-11-11): a one-day or one-week assignment is a
    // temporary cover; when it ends the regular Driver is back on his own.
    public function test_the_regular_driver_comes_back_on_his_own_after_a_one_week_cover(): void
    {
        $rodel = $this->driver('Rodel');
        $ben = $this->driver('Ben');
        $store = $this->store('Store 1');
        $route = $this->route('A', $rodel, [$store]);
        $inWeek = $this->delivery($rodel, $store, date: '2026-11-13', route: $route);
        $afterWeek = $this->delivery($rodel, $store, date: '2026-11-18', route: $route);

        $res = $this->putJson("/api/routes/{$route->id}", ['cover_driver_id' => $ben->id, 'cover_from' => '2026-11-11', 'cover_to' => '2026-11-17'])->assertOk();
        $this->assertSame(['driver' => ['id' => $ben->id, 'name' => 'Ben'], 'from' => '2026-11-11', 'to' => '2026-11-17'], $res->json('route.cover'));
        $this->assertSame($rodel->id, $res->json('route.driver_id')); // still the regular driver
        $this->assertStringContainsString('Ben covers it from Nov 11 to Nov 17, then Rodel is back.', $res->json('message'));

        $this->assertSame($ben->id, $inWeek->fresh()->driver_id);
        $this->assertSame($rodel->id, $afterWeek->fresh()->driver_id);

        // deliveries made later follow the same days
        $c = $this->contract([$store], ['bags' => 5], ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], '2026-11-16', '2026-11-19');
        $this->postJson("/api/delivery-contracts/{$c->id}/approve")->assertOk();
        $drivers = \App\Models\Delivery::where('contract_id', $c->id)->orderBy('scheduled_date')->get()
            ->mapWithKeys(fn ($d) => [$d->scheduledDateString() => $d->driver_id])->all();
        // Nov 18 is skipped: the store already has a delivery that day
        $this->assertSame(['2026-11-16' => $ben->id, '2026-11-17' => $ben->id, '2026-11-19' => $rodel->id], $drivers);

        // after the week nothing has to be changed: on Nov 18 the route is Rodel's again
        Carbon::setTestNow(Carbon::parse('2026-11-18 09:00', 'Asia/Manila'));
        $row = $this->getJson("/api/routes/{$route->id}")->assertOk();
        $this->assertNull($row->json('cover'));
        $this->assertSame($rodel->id, $row->json('driver_today_id'));
        $this->assertSame($rodel->id, $route->fresh()->driverOn('2026-11-18'));
    }

    public function test_one_day_cover_and_ending_a_cover_early(): void
    {
        $rodel = $this->driver('Rodel');
        $ben = $this->driver('Ben');
        $store = $this->store('Store 1');
        $route = $this->route('A', $rodel, [$store]);
        $today = $this->delivery($rodel, $store, date: '2026-11-11', route: $route);
        $tomorrow = $this->delivery($rodel, $store, date: '2026-11-12', route: $route);

        $this->putJson("/api/routes/{$route->id}", ['cover_driver_id' => $ben->id, 'cover_to' => '2026-11-11'])->assertOk(); // from defaults to today
        $this->assertSame($ben->id, $today->fresh()->driver_id);
        $this->assertSame($rodel->id, $tomorrow->fresh()->driver_id);

        $this->putJson("/api/routes/{$route->id}", ['cover_driver_id' => null])->assertOk();
        $this->assertSame($rodel->id, $today->fresh()->driver_id);
        $this->assertNull($route->fresh()->cover_driver_id);
    }

    public function test_a_new_regular_driver_from_next_monday_leaves_this_week_to_the_old_one(): void
    {
        $rodel = $this->driver('Rodel');
        $ana = $this->driver('Ana');
        $store = $this->store('Store 1');
        $route = $this->route('A', $rodel, [$store]);
        $thisWeek = $this->delivery($rodel, $store, date: '2026-11-13', route: $route);
        $nextWeek = $this->delivery($rodel, $store, date: '2026-11-16', route: $route);

        $res = $this->putJson("/api/routes/{$route->id}", ['driver_id' => $ana->id, 'driver_from' => '2026-11-16'])->assertOk();
        $this->assertSame('2026-11-16', $res->json('route.driver_from'));
        $this->assertSame($rodel->id, $res->json('route.previous_driver.id'));
        $this->assertStringContainsString('Ana is the regular driver from Nov 16 (Rodel until then).', $res->json('message'));
        $this->assertSame($rodel->id, $thisWeek->fresh()->driver_id);
        $this->assertSame($ana->id, $nextWeek->fresh()->driver_id);

        // from today: no start date kept
        $res = $this->putJson("/api/routes/{$route->id}", ['driver_id' => $ana->id])->assertOk();
        $this->assertNull($res->json('route.driver_from'));
        $this->assertSame($ana->id, $thisWeek->fresh()->driver_id);
    }

    public function test_cover_and_date_rules(): void
    {
        $rodel = $this->driver('Rodel');
        $ana = $this->driver('Ana');
        $larry = $this->reserve();
        $a = $this->route('A', $rodel, [$this->store('Store 1')]);
        $b = $this->route('B', $ana, [$this->store('Store 2')]);

        $this->putJson("/api/routes/{$a->id}", ['cover_driver_id' => $larry->id])->assertUnprocessable()->assertJsonValidationErrors('cover_to');
        $this->putJson("/api/routes/{$a->id}", ['cover_driver_id' => $larry->id, 'cover_from' => '2026-11-15', 'cover_to' => '2026-11-12'])
            ->assertUnprocessable()->assertJsonValidationErrors('cover_to');
        $this->putJson("/api/routes/{$a->id}", ['cover_driver_id' => $larry->id, 'cover_from' => '2026-11-10', 'cover_to' => '2026-11-12'])
            ->assertUnprocessable()->assertJsonValidationErrors('cover_from');
        $this->putJson("/api/routes/{$a->id}", ['driver_id' => $ana->id, 'driver_from' => '2026-11-10'])
            ->assertUnprocessable()->assertJsonValidationErrors('driver_from');
        // the regular driver of another route cannot cover
        $this->putJson("/api/routes/{$a->id}", ['cover_driver_id' => $ana->id, 'cover_to' => '2026-11-12'])
            ->assertUnprocessable()->assertJsonPath('message', 'This driver already has Route B.');
        // a reserve driver may cover, but not two routes on the same days
        $this->putJson("/api/routes/{$a->id}", ['cover_driver_id' => $larry->id, 'cover_to' => '2026-11-17'])->assertOk();
        $this->putJson("/api/routes/{$b->id}", ['cover_driver_id' => $larry->id, 'cover_from' => '2026-11-15', 'cover_to' => '2026-11-20'])
            ->assertUnprocessable()->assertJsonPath('message', 'This driver already covers Route A on some of those days.');
        // a reserve still cannot be a regular driver
        $this->putJson("/api/routes/{$b->id}", ['driver_id' => $larry->id])->assertUnprocessable();
    }

    public function test_the_code_is_made_by_the_system_and_never_changes(): void
    {
        $this->route('A', null, []);
        $this->route('C', null, []);

        // the next free letter: B, then D
        $b = $this->postJson('/api/routes', ['name' => '  North loop  '])->assertCreated();
        $this->assertSame('B', $b->json('code'));
        $this->assertSame('North loop', $b->json('name'));
        $this->assertSame('D', $this->postJson('/api/routes', ['name' => 'South loop'])->assertCreated()->json('code'));

        // a code cannot be sent, and renaming keeps the code
        $this->postJson('/api/routes', ['code' => 'Z', 'name' => 'X'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->putJson("/api/routes/{$b->json('id')}", ['code' => 'Q'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->putJson("/api/routes/{$b->json('id')}", ['name' => 'North loop 2'])->assertOk()->assertJsonPath('route.code', 'B');
        $this->postJson('/api/routes', [])->assertUnprocessable()->assertJsonValidationErrors('name');

        // after Z comes AA, AB...
        $this->assertSame(['A', 'Z', 'AA', 'AB', 'AZ', 'BA', 'ZZ', 'AAA'], array_map(fn ($n) => \App\Models\DeliveryRoute::letters($n), [1, 26, 27, 28, 52, 53, 702, 703]));
        foreach (range('E', 'Z') as $code) {
            $this->route($code, null, []);
        }
        $this->assertSame('AA', $this->postJson('/api/routes', ['name' => 'Route 27'])->assertCreated()->json('code'));
    }

    public function test_reschedule_keeps_the_deliver_by_time_on_the_new_day(): void
    {
        $store = $this->store('Store 1');
        $d = $this->delivery(null, $store, status: 'queued');
        $d->update(['due_at' => Carbon::parse('2026-11-11 09:30', 'Asia/Manila')->setTimezone(config('app.timezone'))]);

        $this->postJson("/api/deliveries/{$d->id}/reschedule", ['date' => '2026-11-13'])->assertOk();

        $due = $d->fresh()->due_at->copy()->setTimezone('Asia/Manila');
        $this->assertSame('2026-11-13 09:30', $due->format('Y-m-d H:i'));
        $this->assertSame('2026-11-13', $d->fresh()->scheduledDateString());
    }
}
