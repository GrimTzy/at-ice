<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\DeliveryContract;
use App\Models\DeliveryRoute;
use App\Models\DriverLeave;
use App\Models\Employee;
use App\Models\ReferencePrice;
use App\Models\RouteStop;
use App\Models\User;
use Carbon\Carbon;

// Shared setup for the route, reserve driver, reschedule and capacity tests.
// "Today" is Wednesday 2026-11-11 in Manila; each test gets its own minute
// so the not-timed-in memo in DeliveryDispatch never carries over.
trait RouteFixtures
{
    protected User $admin;

    private int $deliveryNo = 0;

    private int $storeNo = 0;

    protected function setUpRoutes(): void
    {
        static $minute = 0;
        $minute = ($minute + 1) % 50;
        Carbon::setTestNow(Carbon::parse('2026-11-11 09:'.str_pad((string) (10 + $minute), 2, '0', STR_PAD_LEFT).':00', 'Asia/Manila'));
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@atice.cloud', 'password' => 'secret-password', 'role' => 'admin']);
        foreach (['bags' => 50, 'small_sack' => 80, 'big_sack' => 120] as $type => $price) {
            foreach (['dealer', 'convenience_store', 'supermarket'] as $customerType) {
                ReferencePrice::updateOrCreate(['customer_type' => $customerType, 'product_type' => $type], ['unit_price' => $price]);
            }
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // A Driver, timed in at $timeIn today (null: not timed in).
    protected function driver(string $name, ?string $timeIn = '06:30:00'): User
    {
        $u = User::create(['name' => $name, 'email' => strtolower($name).'@atice.cloud', 'password' => 'secret-password', 'role' => 'driver']);
        $this->employee($u, 'Driver', $timeIn);

        return $u;
    }

    // Larry: a Driver account marked as reserve driver (no route).
    protected function reserve(string $name = 'Larry', ?string $timeIn = '06:30:00', bool $flag = true): User
    {
        $u = $this->driver($name, $timeIn);
        $u->forceFill(['is_reserve_driver' => $flag])->save();

        return $u;
    }

    protected function employee(User $u, string $position, ?string $timeIn): Employee
    {
        $emp = Employee::create(['user_id' => $u->id, 'employee_id' => 'EMP-'.$u->id.'00', 'first_name' => $u->name, 'last_name' => 'Cruz', 'position' => $position, 'daily_rate' => 600, 'date_hired' => '2026-01-01', 'is_active' => true, 'shift_start' => '07:00', 'shift_end' => '16:00']);
        if ($timeIn) {
            Attendance::create(['employee_id' => $emp->id, 'date' => '2026-11-11', 'time_in' => $timeIn, 'status' => 'present']);
        }

        return $emp;
    }

    protected function absent(User $driver, string $date = '2026-11-11'): void
    {
        DriverLeave::create(['driver_id' => $driver->id, 'date' => $date, 'type' => 'absent']);
    }

    protected function store(string $name, ?float $lat = null, ?float $lng = null, string $type = 'convenience_store'): Customer
    {
        $n = ++$this->storeNo;

        return Customer::create(['name' => $name, 'customer_code' => "CUST-R-{$n}", 'branch_code' => "R-{$n}", 'customer_type' => $type, 'is_active' => true, 'latitude' => $lat, 'longitude' => $lng]);
    }

    // A route with a regular Driver and its stores in the given order.
    protected function route(string $code, ?User $driver, array $stores): DeliveryRoute
    {
        $route = DeliveryRoute::create(['code' => $code, 'name' => "Route {$code}", 'driver_id' => $driver?->id, 'is_active' => true]);
        foreach (array_values($stores) as $i => $s) {
            RouteStop::create(['route_id' => $route->id, 'customer_id' => $s->id, 'stop_order' => $i + 1]);
        }

        return $route;
    }

    protected function delivery(?User $driver, Customer $store, array $items = ['bags' => 10], string $date = '2026-11-11', string $status = 'pending', ?DeliveryRoute $route = null, ?DeliveryContract $contract = null): Delivery
    {
        $d = Delivery::create([
            'delivery_no' => sprintf('DEL-%04d', ++$this->deliveryNo),
            'contract_id' => $contract?->id,
            'route_id' => $route?->id,
            'driver_id' => $driver?->id,
            'customer_id' => $store->id,
            'delivery_type' => 'outbound',
            'status' => $status,
            'scheduled_date' => $date,
            'ordered_at' => "{$date} 08:00:00",
            'prepared_by' => $this->admin->id,
        ]);
        foreach ($items as $type => $qty) {
            $d->items()->create(['product_type' => $type, 'quantity_ordered' => $qty, 'unit_price' => 50]);
        }

        return $d;
    }

    protected function contract(array $stores, array $items, array $days = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat'], string $start = '2026-11-11', string $end = '2026-11-14'): DeliveryContract
    {
        static $n = 0;
        $n++;
        $c = DeliveryContract::create([
            'contract_no' => "DC-TST-2611-{$n}", 'name' => 'Test contract', 'start_date' => $start, 'end_date' => $end,
            'delivery_days' => $days, 'delivery_time' => '08:00:00',
            'items' => collect($items)->map(fn ($q, $t) => ['product_type' => $t, 'quantity' => $q])->values()->all(),
            'status' => 'pending', 'created_by' => $this->admin->id,
        ]);
        $c->stores()->sync(collect($stores)->pluck('id')->all());

        return $c;
    }
}
