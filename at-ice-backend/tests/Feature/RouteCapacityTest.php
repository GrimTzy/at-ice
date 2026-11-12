<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Delivery;
use App\Models\DeliveryContract;
use App\Models\DeliveryItem;
use App\Models\DriverStoreAssignment;
use App\Models\User;
use App\Support\DriverAvailability;
use App\Support\RouteCapacity;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Truck load (150 big sacks, 350 small sacks, 500 bags) and at most 80 stores
// per Driver per day (client, 2026-11-10). Going over only warns; Admin edits
// the limits in Settings > Deliveries.
class RouteCapacityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $day;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@atice.cloud', 'password' => 'secret-password', 'role' => 'admin']);
        $this->day = Carbon::parse(DriverAvailability::today())->addDay()->toDateString();
        Sanctum::actingAs($this->admin);
    }

    private function driver(string $name): User
    {
        return User::create(['name' => $name, 'email' => strtolower($name).'@atice.cloud', 'password' => 'secret-password', 'role' => 'driver']);
    }

    private function store(string $name): Customer
    {
        return Customer::create(['name' => $name, 'customer_code' => 'CUST-'.strtoupper(substr($name, 0, 3)).'-'.random_int(100, 999), 'is_active' => true]);
    }

    private function assign(User $driver, Customer $store): void
    {
        DriverStoreAssignment::create([
            'driver_id' => $driver->id, 'store_id' => $store->id,
            'effective_from' => DriverAvailability::today(), 'created_by' => $this->admin->id,
        ]);
    }

    private function delivery(User $driver, Customer $store, array $items): Delivery
    {
        $d = Delivery::create([
            'delivery_no' => 'DEL-'.random_int(10000, 99999), 'customer_id' => $store->id, 'driver_id' => $driver->id,
            'delivery_type' => 'outbound', 'status' => 'pending', 'scheduled_date' => $this->day, 'prepared_by' => $this->admin->id,
        ]);
        foreach ($items as $type => $qty) {
            DeliveryItem::create(['delivery_id' => $d->id, 'product_type' => $type, 'quantity_ordered' => $qty, 'unit_price' => 1]);
        }

        return $d;
    }

    private function contract(array $stores, array $items): DeliveryContract
    {
        $c = DeliveryContract::create([
            'contract_no' => 'C-'.random_int(1000, 9999), 'name' => 'Test', 'start_date' => $this->day, 'end_date' => $this->day,
            'delivery_days' => DeliveryContract::DAYS, 'delivery_time' => '08:00:00',
            'items' => collect($items)->map(fn ($q, $t) => ['product_type' => $t, 'quantity' => $q])->values()->all(),
            'status' => 'pending', 'created_by' => $this->admin->id,
        ]);
        $c->stores()->sync(collect($stores)->pluck('id')->all());

        return $c;
    }

    public function test_the_default_limits_are_the_clients_truck_load(): void
    {
        $this->assertSame(['big_sack' => 150, 'small_sack' => 350, 'bags' => 500, 'stores' => 80], RouteCapacity::limits());

        $keys = collect($this->getJson('/api/admin-settings/deliveries')->assertOk()->json())->keyBy('key');
        $this->assertSame(150, $keys['delivery.truck_big_sacks']['value']);
        $this->assertSame(80, $keys['delivery.max_stores_per_driver_day']['value']);

        $this->putJson('/api/admin-settings/deliveries', ['key' => 'delivery.truck_bags', 'value' => 450])->assertOk();
        $this->assertSame(450, RouteCapacity::limits()['bags']);
    }

    public function test_a_contract_over_the_truck_load_warns_with_what_the_driver_already_carries(): void
    {
        $ana = $this->driver('Ana');
        $s1 = $this->store('Store One');
        $s2 = $this->store('Store Two');
        $this->assign($ana, $s1);
        $this->assign($ana, $s2);
        $this->delivery($ana, $this->store('Other'), ['big_sack' => 100]); // 100 big sacks already that day
        $contract = $this->contract([$s1, $s2], ['big_sack' => 40]);       // + 2 x 40 = 180

        $check = $this->getJson("/api/delivery-contracts/{$contract->id}/capacity-check")->assertOk();
        $this->assertCount(1, $check->json('warnings'));
        $this->assertSame(
            ['driver_id' => $ana->id, 'driver' => 'Ana', 'date' => $this->day, 'kind' => 'big_sack', 'planned' => 180, 'limit' => 150],
            collect($check->json('warnings.0'))->except('message')->all()
        );
        $this->assertStringContainsString('180 big sacks (limit 150)', $check->json('warnings.0.message'));
        $this->assertSame(0, $check->json('without_driver'));
        $this->assertStringStartsWith('Over the truck limits:', $check->json('warning'));
    }

    public function test_a_contract_that_fits_has_no_warning(): void
    {
        $ana = $this->driver('Ana');
        $s1 = $this->store('Store One');
        $this->assign($ana, $s1);
        $contract = $this->contract([$s1], ['bags' => 100]);

        $check = $this->getJson("/api/delivery-contracts/{$contract->id}/capacity-check")->assertOk();
        $this->assertSame([], $check->json('warnings'));
        $this->assertNull($check->json('warning'));
    }

    public function test_a_contract_store_with_no_driver_is_counted(): void
    {
        $ana = $this->driver('Ana');
        $s1 = $this->store('Store One');
        $s2 = $this->store('Store Two');
        $this->assign($ana, $s1);
        $contract = $this->contract([$s1, $s2], ['bags' => 10]);

        $check = $this->getJson("/api/delivery-contracts/{$contract->id}/capacity-check")->assertOk();
        $this->assertSame(1, $check->json('without_driver'));
        $this->assertSame([], $check->json('warnings'));
    }

    public function test_the_store_limit_counts_each_store(): void
    {
        $ana = $this->driver('Ana');
        $a = $this->store('Store One');
        $b = $this->store('Store Two');
        $this->delivery($ana, $a, ['bags' => 1]);
        $this->delivery($ana, $b, ['bags' => 1]);
        $this->putJson('/api/admin-settings/deliveries', ['key' => 'delivery.max_stores_per_driver_day', 'value' => 1])->assertOk();

        $warnings = RouteCapacity::warningsFor([$ana->id], [$this->day]);
        $this->assertCount(1, $warnings);
        $this->assertSame('stores', $warnings[0]['kind']);
        $this->assertSame(2, $warnings[0]['planned']);
    }

    public function test_a_cancelled_delivery_does_not_count(): void
    {
        $ana = $this->driver('Ana');
        $d = $this->delivery($ana, $this->store('Store One'), ['big_sack' => 200]);
        $this->assertCount(1, RouteCapacity::warningsFor([$ana->id], [$this->day]));

        $d->update(['status' => 'cancelled']);
        $this->assertSame([], RouteCapacity::warningsFor([$ana->id], [$this->day]));
    }
}
