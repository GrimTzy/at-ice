<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\Employee;
use App\Models\LiveEvent;
use App\Models\TruckStockBatch;
use App\Models\TruckStockDraw;
use App\Models\User;
use App\Support\DeliveryDispatch;
use App\Support\Settings;
use App\Support\TruckStock;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// FIFO (owner, 2026-11-09). The trucks are refrigerated and stay out, so:
//   - the oldest order goes first, to the Driver who finished their list first
//   - ice left on a truck stays there, and the oldest batch is sold first
class DeliveryFifoTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Customer $store;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-20 08:30:00', 'Asia/Manila'));
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@atice.cloud', 'password' => 'secret-password', 'role' => 'admin']);
        Sanctum::actingAs($this->admin);
        $this->store = Customer::create(['name' => 'Starmall', 'customer_code' => 'CUST-STA-001', 'branch_code' => 'STA-SJDM-001', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function driver(string $name, ?string $timeIn): User
    {
        $u = User::create(['name' => $name, 'email' => strtolower($name).'@atice.cloud', 'password' => 'secret-password', 'role' => 'driver']);
        $emp = Employee::create(['user_id' => $u->id, 'employee_id' => 'EMP-'.$u->id.'00', 'first_name' => $name, 'last_name' => 'Cruz', 'position' => 'Driver', 'daily_rate' => 600, 'date_hired' => '2026-01-01', 'is_active' => true, 'shift_start' => '07:00', 'shift_end' => '16:00']);
        if ($timeIn) {
            Attendance::create(['employee_id' => $emp->id, 'date' => '2026-10-20', 'time_in' => $timeIn, 'status' => 'present']);
        }

        return $u;
    }

    private int $no = 0;

    private function delivery(?User $driver, string $status, string $orderedAt, ?string $deliveredAt = null): Delivery
    {
        return Delivery::create([
            'delivery_no' => 'DEL-'.str_pad((string) ++$this->no, 4, '0', STR_PAD_LEFT),
            'driver_id' => $driver?->id, 'customer_id' => $this->store->id, 'delivery_type' => 'outbound',
            'status' => $status, 'scheduled_date' => '2026-10-20', 'ordered_at' => "2026-10-20 {$orderedAt}",
            'delivered_at' => $deliveredAt ? Carbon::parse("2026-10-20 {$deliveredAt}", 'Asia/Manila')->setTimezone(config('app.timezone')) : null,
            'prepared_by' => $this->admin->id,
        ]);
    }

    // Ana: no stops today, free since her 06:30 time in. Ben: done at 07:30.
    // Carl: still has a stop. Dan: not timed in, so he is off.
    private function crew(): array
    {
        $ana = $this->driver('Ana', '06:30:00');
        $ben = $this->driver('Ben', '06:00:00');
        $carl = $this->driver('Carl', '06:10:00');
        $dan = $this->driver('Dan', null);
        $this->delivery($ben, 'delivered', '06:45:00', '07:30');
        $this->delivery($carl, 'pending', '12:00:00');

        return [$ana, $ben, $carl, $dan];
    }

    public function test_the_driver_who_finished_first_is_first_in_line(): void
    {
        [$ana, $ben, $carl] = $this->crew();

        $this->assertSame([$ana->id, $ben->id, $carl->id], DeliveryDispatch::availableDrivers());
    }

    public function test_an_off_drivers_stops_go_oldest_first_to_the_first_done_driver(): void
    {
        [$ana, $ben, $carl, $dan] = $this->crew();
        // made newest first, so the order of the rows does not decide it
        $late = $this->delivery($dan, 'pending', '11:00:00');
        $mid = $this->delivery($dan, 'pending', '10:00:00');
        $early = $this->delivery($dan, 'pending', '09:00:00');

        DeliveryDispatch::reassign($dan->id, '2026-10-20');

        $this->assertSame($ana->id, $early->fresh()->driver_id);
        $this->assertSame($ben->id, $mid->fresh()->driver_id);
        $this->assertSame($carl->id, $late->fresh()->driver_id);
        $this->assertSame($dan->id, $early->fresh()->reassigned_from_driver_id);
    }

    public function test_the_queue_is_first_in_first_out(): void
    {
        [$ana, $ben] = $this->crew();
        $newer = $this->delivery(null, 'queued', '10:00:00');
        $older = $this->delivery(null, 'queued', '08:00:00');

        DeliveryDispatch::assignQueued();

        $this->assertSame($ana->id, $older->fresh()->driver_id);
        $this->assertSame($ben->id, $newer->fresh()->driver_id);
        $this->assertSame('pending', $older->fresh()->status);
    }

    public function test_the_board_shows_when_each_driver_finished_their_list(): void
    {
        [$ana, $ben, $carl] = $this->crew();

        $drivers = collect($this->getJson('/api/deliveries/board?date=2026-10-20')->assertOk()->json('drivers'))->keyBy('id');
        $this->assertSame('2026-10-20 06:30:00', $drivers[$ana->id]['trip_completed_at']);
        $this->assertSame('2026-10-20 07:30:00', $drivers[$ben->id]['trip_completed_at']);
        $this->assertNull($drivers[$carl->id]['trip_completed_at']);
    }

    public function test_ice_left_on_the_truck_is_sold_before_newer_ice(): void
    {
        Settings::set('delivery.allow_rolling_stock_fifo', true); // off by default since 2026-11-10, still works
        $driver = $this->driver('Ana', '06:30:00');
        $yesterday = $this->delivery($driver, 'delivered', '07:00:00');
        $today = $this->delivery($driver, 'in_transit', '09:00:00');

        // Yesterday 30 bags were loaded and 20 delivered: 10 stay on the truck
        $old = TruckStockBatch::create(['driver_id' => $driver->id, 'product_type' => 'bags', 'quantity_loaded' => 30, 'quantity_left' => 30, 'loaded_at' => '2026-10-19 06:00:00', 'delivery_id' => $yesterday->id]);
        TruckStock::draw($yesterday, [['product_type' => 'bags', 'quantity' => 20]]);
        $this->assertSame(10, $old->fresh()->quantity_left);

        // Today 25 more are loaded, then 15 are delivered
        $new = TruckStockBatch::create(['driver_id' => $driver->id, 'product_type' => 'bags', 'quantity_loaded' => 25, 'quantity_left' => 25, 'loaded_at' => '2026-10-20 06:00:00', 'delivery_id' => $today->id]);
        TruckStock::draw($today, [['product_type' => 'bags', 'quantity' => 15]]);

        $this->assertSame(0, $old->fresh()->quantity_left);  // yesterday's 10 first
        $this->assertSame(20, $new->fresh()->quantity_left); // then 5 of today's
        $draws = TruckStockDraw::where('delivery_id', $today->id)->orderBy('id')->get();
        $this->assertSame([[$old->id, 10], [$new->id, 5]], $draws->map(fn ($d) => [$d->batch_id, $d->quantity])->all());
    }

    public function test_a_quantity_no_loading_covered_is_still_recorded(): void
    {
        Settings::set('delivery.allow_rolling_stock_fifo', true); // off by default since 2026-11-10, still works
        $driver = $this->driver('Ana', '06:30:00');
        $d = $this->delivery($driver, 'in_transit', '09:00:00');
        TruckStockBatch::create(['driver_id' => $driver->id, 'product_type' => 'bags', 'quantity_loaded' => 5, 'quantity_left' => 5, 'loaded_at' => '2026-10-20 06:00:00']);

        TruckStock::draw($d, [['product_type' => 'bags', 'quantity' => 8]]);

        $this->assertSame(8, (int) TruckStockDraw::where('delivery_id', $d->id)->sum('quantity'));
        $this->assertSame(3, (int) TruckStockDraw::where('delivery_id', $d->id)->whereNull('batch_id')->sum('quantity'));
    }

    public function test_hr_can_turn_off_the_automatic_queue(): void
    {
        $this->crew();
        Settings::set('delivery.auto_queue_on_trip_completion', false);
        $queued = $this->delivery(null, 'queued', '08:00:00');

        DeliveryDispatch::assignQueued();

        $this->assertSame('queued', $queued->fresh()->status); // Admin picks the driver
        $this->assertNull($queued->fresh()->driver_id);
    }

    public function test_hr_can_turn_off_truck_stock_tracking(): void
    {
        $driver = $this->driver('Ana', '06:30:00');
        $d = $this->delivery($driver, 'in_transit', '09:00:00');
        Settings::set('delivery.allow_rolling_stock_fifo', false);

        TruckStock::draw($d, [['product_type' => 'bags', 'quantity' => 8]]);

        $this->assertSame(0, TruckStockDraw::count());
    }

    public function test_admin_hears_once_about_a_delivery_too_long_in_transit(): void
    {
        $driver = $this->driver('Ana', '06:30:00');
        $d = $this->delivery($driver, 'in_transit', '03:00:00');
        $d->forceFill(['dispatched_at' => now()->subHours(5)])->save();
        $fresh = $this->delivery($driver, 'in_transit', '08:00:00');
        $fresh->forceFill(['dispatched_at' => now()->subHour()])->save();

        $this->assertSame(1, DeliveryDispatch::sendTransitAlerts()); // the 4-hour default
        $this->assertSame(0, DeliveryDispatch::sendTransitAlerts()); // not twice
        $this->assertStringContainsString($d->delivery_no, LiveEvent::latest('id')->value('message'));

        Settings::set('delivery.max_transit_hours_before_alert', 0); // off
        $fresh->forceFill(['dispatched_at' => now()->subHours(9)])->save();
        $this->assertSame(0, DeliveryDispatch::sendTransitAlerts());
    }

    public function test_a_loading_count_goes_on_the_drivers_truck(): void
    {
        Settings::set('delivery.allow_rolling_stock_fifo', true); // off by default since 2026-11-10, still works
        $driver = $this->driver('Ana', '06:30:00');
        $d = $this->delivery($driver, 'pending', '09:00:00');
        $checker = User::create(['name' => 'Checker', 'email' => 'checker@atice.cloud', 'password' => 'secret-password', 'role' => 'employee', 'sub_role' => 'checker']);
        $emp = Employee::create(['user_id' => $checker->id, 'employee_id' => 'EMP-0099', 'first_name' => 'Check', 'last_name' => 'Er', 'position' => 'Checker', 'daily_rate' => 600, 'date_hired' => '2026-01-05', 'is_active' => true]);
        Attendance::create(['employee_id' => $emp->id, 'date' => '2026-10-20', 'time_in' => '06:00:00', 'status' => 'present']);
        Sanctum::actingAs($checker);

        $this->postJson('/api/ice-loadings', [
            'delivery_id' => $d->id, 'loaded_at' => '2026-10-20T06:15',
            'items' => [['product_type' => 'bags', 'quantity' => 40], ['product_type' => 'big_sack', 'quantity' => 2]],
        ])->assertCreated();

        $batches = TruckStockBatch::where('driver_id', $driver->id)->orderBy('product_type')->get();
        $this->assertSame([['bags', 40], ['big_sack', 2]], $batches->map(fn ($b) => [$b->product_type, $b->quantity_left])->all());
    }

    // A reserve driver only takes an absent Driver's whole route
    // (ReserveDriverTest), so they are not in the FIFO pool.
    public function test_a_reserve_driver_is_not_in_the_fifo_pool(): void
    {
        $ana = $this->driver('Ana', '06:30:00');   // free since 06:30
        $ben = $this->driver('Ben', '07:00:00');   // free later than Ana
        $this->putJson("/api/drivers/{$ben->id}/reserve", ['is_reserve' => true])->assertOk();

        $this->assertSame([$ana->id], DeliveryDispatch::availableDrivers());

        $this->putJson("/api/drivers/{$ben->id}/reserve", ['is_reserve' => false])->assertOk();
        $this->assertSame([$ana->id, $ben->id], DeliveryDispatch::availableDrivers());
    }
}
