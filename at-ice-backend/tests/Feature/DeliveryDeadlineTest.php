<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Customer;
use App\Models\CustomerPrice;
use App\Models\Delivery;
use App\Models\DeliveryNotification;
use App\Models\Employee;
use App\Models\LiveEvent;
use App\Models\User;
use App\Support\DeliveryDispatch;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Deliver by is a deadline (owner, 2026-11-10): the ice reaches the store at
// that time or before it. After it, the delivery is late.
class DeliveryDeadlineTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Customer $store;

    private int $no = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-20 08:30:00', 'Asia/Manila'));
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@atice.cloud', 'password' => 'secret-password', 'role' => 'admin']);
        Sanctum::actingAs($this->admin);
        $login = User::create(['name' => 'Starmall', 'email' => 'starmall@atice.cloud', 'password' => 'secret-password', 'role' => 'customer']);
        $this->store = Customer::create(['user_id' => $login->id, 'name' => 'Starmall', 'customer_code' => 'CUST-STA-001', 'branch_code' => 'STA-SJDM-001', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function at(string $time): Carbon
    {
        return Carbon::parse("2026-10-20 {$time}", 'Asia/Manila')->setTimezone(config('app.timezone'));
    }

    private function delivery(string $status, ?string $dueBy, string $orderedAt = '07:00', ?string $deliveredAt = null, ?User $driver = null): Delivery
    {
        return Delivery::create([
            'delivery_no' => 'DEL-'.str_pad((string) ++$this->no, 4, '0', STR_PAD_LEFT),
            'driver_id' => $driver?->id, 'customer_id' => $this->store->id, 'delivery_type' => 'outbound',
            'status' => $status, 'scheduled_date' => '2026-10-20', 'ordered_at' => $this->at($orderedAt),
            'due_at' => $dueBy ? $this->at($dueBy) : null,
            'delivered_at' => $deliveredAt ? $this->at($deliveredAt) : null,
            'prepared_by' => $this->admin->id,
        ]);
    }

    private function driver(string $name): User
    {
        $u = User::create(['name' => $name, 'email' => strtolower($name).'@atice.cloud', 'password' => 'secret-password', 'role' => 'driver']);
        $emp = Employee::create(['user_id' => $u->id, 'employee_id' => 'EMP-'.$u->id.'00', 'first_name' => $name, 'last_name' => 'Cruz', 'position' => 'Driver', 'daily_rate' => 600, 'date_hired' => '2026-01-01', 'is_active' => true, 'shift_start' => '07:00', 'shift_end' => '16:00']);
        Attendance::create(['employee_id' => $emp->id, 'date' => '2026-10-20', 'time_in' => '06:30:00', 'status' => 'present']);

        return $u;
    }

    public function test_delivered_at_or_before_the_time_is_on_time_and_after_it_is_late(): void
    {
        $this->assertSame(['state' => 'on_time', 'minutes' => 0], $this->delivery('delivered', '10:00', deliveredAt: '09:40')->timing);
        $this->assertSame(['state' => 'on_time', 'minutes' => 0], $this->delivery('delivered', '10:00', deliveredAt: '10:00')->timing);
        $this->assertSame(['state' => 'late', 'minutes' => 25], $this->delivery('delivered', '10:00', deliveredAt: '10:25')->timing);
    }

    public function test_not_delivered_yet_is_due_until_the_time_then_overdue(): void
    {
        $this->assertSame(['state' => 'due', 'minutes' => 90], $this->delivery('in_transit', '10:00')->timing);
        $this->assertSame(['state' => 'overdue', 'minutes' => 30], $this->delivery('pending', '08:00')->timing);
        $this->assertNull($this->delivery('cancelled', '08:00')->timing);
        $this->assertNull($this->delivery('pending', null)->timing);
    }

    public function test_a_store_delivery_needs_a_deliver_by_time_later_than_now(): void
    {
        $driver = $this->driver('Ana');
        CustomerPrice::create(['customer_id' => $this->store->id, 'product_type' => 'bags', 'unit_price' => 50]);
        $payload = ['driver_id' => $driver->id, 'customer_id' => $this->store->id, 'delivery_type' => 'outbound', 'scheduled_date' => '2026-10-20', 'items' => [['product_type' => 'bags', 'quantity_ordered' => 10]]];

        $this->postJson('/api/deliveries', $payload)->assertUnprocessable()->assertJsonValidationErrors('deliver_by');
        $this->postJson('/api/deliveries', $payload + ['deliver_by' => '08:00'])->assertUnprocessable()->assertJsonValidationErrors('deliver_by');

        $id = $this->postJson('/api/deliveries', $payload + ['deliver_by' => '11:00'])->assertCreated()->json('id');
        $this->assertTrue($this->at('11:00')->eq(Delivery::find($id)->due_at));
    }

    public function test_the_earliest_deliver_by_goes_first_then_the_oldest_order(): void
    {
        $ana = $this->driver('Ana');
        // ordered first but needed later
        $afternoon = $this->delivery('queued', '15:00', '06:00');
        $morningNew = $this->delivery('queued', '10:00', '07:30');
        $morningOld = $this->delivery('queued', '10:00', '07:00');
        $ben = $this->driver('Ben');

        DeliveryDispatch::assignQueued();

        // Ana and Ben are both free: round-robin in the order of the queue
        $this->assertSame($ana->id, $morningOld->fresh()->driver_id);
        $this->assertSame($ben->id, $morningNew->fresh()->driver_id);
        $this->assertSame($ana->id, $afternoon->fresh()->driver_id);
    }

    public function test_an_overdue_delivery_tells_the_store_and_admin_once(): void
    {
        $late = $this->delivery('in_transit', '08:00');
        $this->delivery('in_transit', '10:00'); // not due yet
        $this->delivery('delivered', '08:00', deliveredAt: '07:50');

        $this->assertSame(1, DeliveryDispatch::sendOverdueNotices());
        $this->assertSame(0, DeliveryDispatch::sendOverdueNotices());

        $notice = DeliveryNotification::where('delivery_id', $late->id)->where('kind', 'overdue')->where('channel', 'portal')->first();
        $this->assertNotNull($notice);
        $this->assertStringContainsString('due by 8:00 AM', $notice->message);
        $this->assertSame(1, LiveEvent::where('message', 'like', "%{$late->delivery_no}%past its Deliver by time%")->count());
    }

    public function test_admin_can_move_the_deliver_by_time_and_the_store_is_told(): void
    {
        $d = $this->delivery('pending', '10:00');

        $this->putJson("/api/deliveries/{$d->id}", ['deliver_by' => '08:00'])->assertUnprocessable()->assertJsonValidationErrors('deliver_by');
        $this->putJson("/api/deliveries/{$d->id}", ['deliver_by' => '13:30'])->assertOk();

        $this->assertTrue($this->at('13:30')->eq($d->fresh()->due_at));
        $this->assertSame(1, DeliveryNotification::where('delivery_id', $d->id)->where('kind', 'time_changed')->count());
    }
}
