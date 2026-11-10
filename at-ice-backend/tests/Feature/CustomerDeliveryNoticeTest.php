<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Delivery;
use App\Models\DeliveryNotification;
use App\Models\DriverStoreAssignment;
use App\Models\User;
use App\Support\DeliveryDispatch;
use App\Support\DriverAvailability;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Every way a delivery changes driver or is called off tells the store on its
// portal (My Deliveries notices).
class CustomerDeliveryNoticeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $storeUser;

    private Customer $store;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->storeUser = User::factory()->create(['role' => 'customer']);
        $this->store = Customer::create([
            'name' => 'Puregold Tungko', 'customer_code' => 'CUST-PG-021', 'branch_code' => 'PG-SJDM-021',
            'invoice_code' => 'PG', 'customer_type' => 'supermarket', 'is_active' => true,
            'user_id' => $this->storeUser->id,
        ]);
    }

    private function delivery(?int $driverId, string $date, string $no = 'DEL-0501'): Delivery
    {
        return Delivery::create([
            'delivery_no' => $no, 'customer_id' => $this->store->id, 'driver_id' => $driverId,
            'delivery_type' => 'outbound', 'status' => 'pending', 'scheduled_date' => $date,
            'prepared_by' => $this->admin->id,
        ]);
    }

    private function tomorrow(int $days = 1): string
    {
        return Carbon::parse(DriverAvailability::today())->addDays($days)->toDateString();
    }

    private function portalNotices(): array
    {
        app('auth')->forgetGuards();
        Sanctum::actingAs($this->storeUser);

        return $this->getJson('/api/my-delivery-notices')->assertOk()->json();
    }

    public function test_admin_changing_the_driver_tells_the_store_with_the_new_driver_name(): void
    {
        $first = User::factory()->create(['role' => 'driver', 'name' => 'Ramon Dela Cruz']);
        $second = User::factory()->create(['role' => 'driver', 'name' => 'Joel Mendoza']);
        $d = $this->delivery($first->id, $this->tomorrow());
        Sanctum::actingAs($this->admin);

        $this->putJson("/api/deliveries/{$d->id}", ['driver_id' => $second->id])->assertOk();

        $notices = $this->portalNotices();
        $this->assertCount(1, $notices);
        $this->assertStringContainsString('reassigned to driver Joel Mendoza', $notices[0]['message']);
        $this->assertStringNotContainsString('Ramon', $notices[0]['message']);
    }

    public function test_saving_a_delivery_with_the_same_driver_sends_nothing(): void
    {
        $driver = User::factory()->create(['role' => 'driver']);
        $d = $this->delivery($driver->id, $this->tomorrow());
        Sanctum::actingAs($this->admin);

        $this->putJson("/api/deliveries/{$d->id}", ['driver_id' => $driver->id, 'notes' => 'Back gate'])->assertOk();

        $this->assertSame(0, DeliveryNotification::count());
    }

    public function test_a_new_store_assignment_tells_the_store_once_for_all_its_moved_deliveries(): void
    {
        $old = User::factory()->create(['role' => 'driver']);
        $new = User::factory()->create(['role' => 'driver', 'name' => 'Eduardo Bautista']);
        $this->delivery($old->id, $this->tomorrow(1), 'DEL-0502');
        $this->delivery($old->id, $this->tomorrow(2), 'DEL-0503');
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/driver-store-assignments', [
            'driver_id' => $new->id, 'store_ids' => [$this->store->id], 'effective_from' => DriverAvailability::today(),
        ])->assertCreated();

        $notices = $this->portalNotices();
        $this->assertCount(1, $notices);
        $this->assertStringContainsString('your 2 upcoming deliveries will be handled by driver Eduardo Bautista', $notices[0]['message']);
    }

    public function test_ending_an_assignment_tells_the_store_its_deliveries_are_moving(): void
    {
        $driver = User::factory()->create(['role' => 'driver']);
        $a = DriverStoreAssignment::create([
            'driver_id' => $driver->id, 'store_id' => $this->store->id,
            'effective_from' => DriverAvailability::today(), 'created_by' => $this->admin->id,
        ]);
        $this->delivery($driver->id, $this->tomorrow());
        Sanctum::actingAs($this->admin);

        $this->deleteJson("/api/driver-store-assignments/{$a->id}")->assertOk();

        $notices = $this->portalNotices();
        $this->assertCount(1, $notices);
        $this->assertStringContainsString('being moved to another driver', $notices[0]['message']);
    }

    public function test_cancelling_a_delivery_tells_the_store_without_the_internal_reason(): void
    {
        $driver = User::factory()->create(['role' => 'driver']);
        $d = $this->delivery($driver->id, $this->tomorrow());
        Sanctum::actingAs($this->admin);

        $this->putJson("/api/deliveries/{$d->id}/cancel", ['reason' => 'Truck engine trouble'])->assertOk();

        $notices = $this->portalNotices();
        $this->assertCount(1, $notices);
        $this->assertStringContainsString('was cancelled', $notices[0]['message']);
        $this->assertStringNotContainsString('engine', $notices[0]['message']);
    }

    public function test_a_driver_off_today_still_sends_the_delay_notice_once_and_later_changes_still_notify(): void
    {
        $off = User::factory()->create(['role' => 'driver']);
        $d = $this->delivery($off->id, DriverAvailability::today());

        DeliveryDispatch::reassign($off->id, DriverAvailability::today()); // nobody free: queued
        DeliveryDispatch::sendDelayNotices();
        DeliveryDispatch::sendDelayNotices();
        $this->assertSame(1, DeliveryNotification::where('delivery_id', $d->id)->where('kind', 'delay')->where('channel', 'portal')->count());

        DeliveryDispatch::notifyChange($d->fresh('customer'), 'cancelled', 'Your delivery DEL-0501 was cancelled.');
        $this->assertCount(2, $this->portalNotices());
    }
}
