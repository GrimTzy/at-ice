<?php

namespace Tests\Feature;

use App\Models\Delivery;
use App\Models\User;
use App\Support\RouteCapacity;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Truck load (150 big sacks, 350 small sacks, 500 bags) and at most 80 stores
// per Driver per day (client, 2026-11-10). Going over warns; Admin edits the
// limits in Settings > Deliveries.
class RouteCapacityTest extends TestCase
{
    use RefreshDatabase;
    use RouteFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRoutes();
        Sanctum::actingAs($this->admin);
    }

    public function test_the_default_limits_are_the_clients_truck_load(): void
    {
        $this->assertSame(['big_sack' => 150, 'small_sack' => 350, 'bags' => 500, 'stores' => 80], RouteCapacity::limits());

        $keys = collect($this->getJson('/api/admin-settings/deliveries')->assertOk()->json())->keyBy('key');
        $this->assertSame(150, $keys['delivery.truck_big_sacks']['value']);
        $this->assertSame(80, $keys['delivery.max_stores_per_driver_day']['value']);
        $this->assertEqualsWithDelta(14.7502926, $keys['plant.latitude']['value'], 0.0000001);
        $this->assertEqualsWithDelta(121.0739105, $keys['plant.longitude']['value'], 0.0000001);
        $this->putJson('/api/admin-settings/deliveries', ['key' => 'delivery.truck_bags', 'value' => 450])->assertOk();
        $this->assertSame(450, RouteCapacity::limits()['bags']);
    }

    public function test_a_contract_over_the_truck_load_warns_but_can_still_be_approved(): void
    {
        $ana = $this->driver('Ana');
        $s1 = $this->store('Store 1');
        $s2 = $this->store('Store 2');
        $this->route('A', $ana, [$s1, $s2]);
        // already 100 big sacks on Thursday
        $this->delivery($ana, $this->store('Other'), ['big_sack' => 100], '2026-11-12');
        // 2 stores x 40 = 80 a day: Wed 80 (fine), Thu 180 (over)
        $contract = $this->contract([$s1, $s2], ['big_sack' => 40], ['wed', 'thu'], '2026-11-11', '2026-11-12');

        $check = $this->getJson("/api/delivery-contracts/{$contract->id}/capacity-check")->assertOk();
        $this->assertCount(1, $check->json('warnings'));
        $this->assertSame(['driver_id' => $ana->id, 'driver' => 'Ana', 'date' => '2026-11-12', 'kind' => 'big_sack', 'planned' => 180, 'limit' => 150],
            collect($check->json('warnings.0'))->except('message')->all());
        $this->assertSame('Ana on Nov 12: 180 big sacks (limit 150).', $check->json('warnings.0.message'));
        $this->assertSame(0, $check->json('without_driver'));

        $res = $this->postJson("/api/delivery-contracts/{$contract->id}/approve")->assertOk();
        $this->assertSame(4, $res->json('created'));
        $this->assertCount(1, $res->json('warnings'));
        $this->assertStringContainsString('Over the truck limits: Ana on Nov 12', $res->json('message'));
    }

    // A contract is approved on save and can be edited (owner, 2026-11-11):
    // both return the truck-limit warnings for the deliveries they make.
    public function test_saving_and_editing_a_contract_warn_over_the_truck_load(): void
    {
        $ana = $this->driver('Ana');
        $s1 = $this->store('Store 1');
        $s2 = $this->store('Store 2');
        $this->route('A', $ana, [$s1, $s2]);
        $this->delivery($ana, $this->store('Other'), ['big_sack' => 100], '2026-11-12');
        $body = [
            'name' => 'Weekly run', 'store_ids' => [$s1->id, $s2->id],
            'start_date' => '2026-11-11', 'end_date' => '2026-11-12',
            'delivery_days' => ['wed', 'thu'], 'delivery_time' => '08:00',
            'items' => [['product_type' => 'big_sack', 'quantity' => 40]],
        ];

        // Thu: 100 + 2 x 40 = 180, over 150
        $res = $this->postJson('/api/delivery-contracts', $body)->assertCreated();
        $this->assertSame('approved', $res->json('status'));
        $this->assertCount(1, $res->json('warnings'));
        $this->assertSame(180, $res->json('warnings.0.planned'));
        $this->assertStringContainsString('Over the truck limits: Ana on Nov 12', $res->json('message'));

        // the contract's own deliveries are made again, not counted twice: 100 + 2 x 30 = 160
        $id = $res->json('id');
        $edit = $this->putJson("/api/delivery-contracts/{$id}", ['items' => [['product_type' => 'big_sack', 'quantity' => 30]]] + $body)->assertOk();
        $this->assertSame(160, $edit->json('warnings.0.planned'));

        // 100 + 2 x 20 = 140: no warning
        $edit = $this->putJson("/api/delivery-contracts/{$id}", ['items' => [['product_type' => 'big_sack', 'quantity' => 20]]] + $body)->assertOk();
        $this->assertSame([], $edit->json('warnings'));
        $this->assertNull($edit->json('warning'));
    }

    public function test_the_store_limit_counts_each_tenant(): void
    {
        Settings::set('delivery.max_stores_per_driver_day', 2);
        $ana = $this->driver('Ana');
        $tenants = [$this->store('A - SM'), $this->store('B - SM'), $this->store('C - SM')];
        $this->route('D', $ana, $tenants);
        $contract = $this->contract($tenants, ['bags' => 1], ['wed'], '2026-11-11', '2026-11-11');

        $warnings = $this->getJson("/api/delivery-contracts/{$contract->id}/capacity-check")->assertOk()->json('warnings');
        $this->assertSame('stores', $warnings[0]['kind']);
        $this->assertSame(3, $warnings[0]['planned']);
    }

    public function test_a_contract_store_with_no_driver_is_counted(): void
    {
        $contract = $this->contract([$this->store('Lonely')], ['bags' => 1], ['wed', 'thu'], '2026-11-11', '2026-11-12');

        $this->getJson("/api/delivery-contracts/{$contract->id}/capacity-check")->assertOk()->assertJsonPath('without_driver', 2);
    }

    public function test_capacity_shows_each_drivers_load_for_a_day(): void
    {
        $ana = $this->driver('Ana');
        $s = $this->store('Store');
        $this->route('A', $ana, [$s]);
        $this->delivery($ana, $s, ['big_sack' => 20, 'bags' => 30]);
        $this->delivery($ana, $this->store('Other'), ['bags' => 5]);
        $this->delivery($ana, $this->store('Cancelled'), ['bags' => 500], status: 'cancelled');

        $row = $this->getJson('/api/routes/capacity?date=2026-11-11')->assertOk()->json('drivers.0');
        $this->assertSame('A', $row['route_code']);
        $this->assertSame(20, $row['big_sack']);
        $this->assertSame(35, $row['bags']);
        $this->assertSame(2, $row['stores']);
        $this->assertSame([], $row['over']);
    }

    public function test_admin_editing_a_delivery_gets_a_warning_when_the_driver_goes_over(): void
    {
        $ana = $this->driver('Ana');
        $this->delivery($ana, $this->store('Big order'), ['bags' => 490]);
        $d = $this->delivery(null, $this->store('Store'), ['bags' => 20], status: 'queued');

        $res = $this->putJson("/api/deliveries/{$d->id}", ['driver_id' => $ana->id])->assertOk();
        $this->assertSame('bags', $res->json('capacity_warnings.0.kind'));
        $this->assertSame(510, $res->json('capacity_warnings.0.planned'));
        $this->assertSame($ana->id, Delivery::find($d->id)->driver_id); // a warning, not a block
    }

    public function test_the_reserve_driver_can_be_picked_when_editing_a_delivery(): void
    {
        $larry = $this->reserve();
        // a Maintenance employee is not a Driver
        $plain = User::create(['name' => 'Mario', 'email' => 'mario@atice.cloud', 'password' => 'secret-password', 'role' => 'employee', 'sub_role' => 'maintenance']);
        $this->employee($plain, 'Maintenance', '06:30:00');
        $d = $this->delivery(null, $this->store('Store'), status: 'queued');

        $this->putJson("/api/deliveries/{$d->id}", ['driver_id' => $plain->id])->assertUnprocessable();
        $this->putJson("/api/deliveries/{$d->id}", ['driver_id' => $larry->id])->assertOk();
        $this->assertSame($larry->id, $d->fresh()->driver_id);
    }
}
