<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerPrice;
use App\Models\Delivery;
use App\Models\DeliveryArrival;
use App\Models\DeliveryCrew;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// A delivery scheduled for a later day (business timezone, Asia/Manila) cannot
// be started by the Driver, marked Arrived, or confirmed by the Salesman.
class LaterDayDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private User $driver;

    private User $salesman;

    private Customer $store;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->driver = User::create(['name' => 'Driver', 'email' => 'driver@atice.cloud', 'password' => 'secret-password', 'role' => 'driver']);
        $this->salesman = User::create(['name' => 'Salesman', 'email' => 'sales@atice.cloud', 'password' => 'secret-password', 'role' => 'employee', 'sub_role' => 'salesman']);
        $this->store = Customer::create(['name' => 'Alfamart Muzon', 'customer_code' => 'CUST-ALF-001', 'branch_code' => 'ALF-SJDM-001', 'invoice_code' => 'PG', 'is_active' => true, 'latitude' => 14.8100, 'longitude' => 121.0500]);
        CustomerPrice::create(['customer_id' => $this->store->id, 'product_type' => 'bags', 'unit_price' => 50]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // "Now" as a Manila wall-clock time.
    private function at(string $manila): void
    {
        Carbon::setTestNow(Carbon::parse($manila, 'Asia/Manila'));
        $this->driver = $this->timedIn($this->driver);
        $this->salesman = $this->timedIn($this->salesman);
    }

    private function delivery(string $date, string $status): Delivery
    {
        static $n = 0;
        $n++;

        return Delivery::create([
            'delivery_no' => sprintf('DEL-%04d', $n), 'driver_id' => $this->driver->id, 'customer_id' => $this->store->id,
            'status' => $status, 'scheduled_date' => $date, 'prepared_by' => $this->driver->id,
        ]);
    }

    private function confirm(Delivery $d)
    {
        DeliveryCrew::create(['date' => now('Asia/Manila')->toDateString(), 'salesman_id' => $this->salesman->id, 'driver_id' => $this->driver->id]);
        foreach ([[$this->driver->id, 'driver'], [$this->salesman->id, 'salesman']] as [$userId, $role]) {
            DeliveryArrival::create(['delivery_id' => $d->id, 'user_id' => $userId, 'role' => $role, 'latitude' => 14.81, 'longitude' => 121.05, 'distance_m' => 10, 'arrived_at' => now()]);
        }
        Sanctum::actingAs($this->salesman);
        $jpeg = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=');

        return $this->post('/api/delivery-confirmation', [
            'delivery_id' => $d->id,
            'products' => [['product_type' => 'bags', 'quantity' => 2]],
            'photo' => UploadedFile::fake()->createWithContent('photo.jpg', $jpeg),
        ], ['Accept' => 'application/json']);
    }

    public function test_driver_cannot_start_a_delivery_scheduled_for_tomorrow(): void
    {
        $this->at('2026-11-07 23:30');
        $tomorrow = $this->delivery('2026-11-08', 'pending');

        Sanctum::actingAs($this->driver);
        $this->patchJson("/api/driver/deliveries/{$tomorrow->id}/status", ['status' => 'in_transit'])
            ->assertUnprocessable()
            ->assertJsonPath('message', "{$tomorrow->delivery_no} is scheduled for Nov 8, 2026. It can be started on that day, not before.");
        $this->assertSame('pending', $tomorrow->fresh()->status);
    }

    public function test_the_day_follows_manila_time_not_utc(): void
    {
        // 00:30 on Nov 8 in Manila is still Nov 7 in UTC: the Nov 8 delivery is today.
        $this->at('2026-11-08 00:30');
        $today = $this->delivery('2026-11-08', 'pending');
        $yesterday = $this->delivery('2026-11-07', 'pending');

        Sanctum::actingAs($this->driver);
        $this->patchJson("/api/driver/deliveries/{$today->id}/status", ['status' => 'in_transit'])->assertOk();
        // an overdue delivery from an earlier day can still be started
        $this->patchJson("/api/driver/deliveries/{$yesterday->id}/status", ['status' => 'in_transit'])->assertOk();
    }

    public function test_arrived_is_refused_for_a_later_day(): void
    {
        $this->at('2026-11-07 10:00');
        // in_transit already (e.g. started before this rule existed)
        $later = $this->delivery('2026-11-09', 'in_transit');

        Sanctum::actingAs($this->driver);
        $this->postJson("/api/driver/deliveries/{$later->id}/arrive", ['latitude' => 14.8101, 'longitude' => 121.0501])
            ->assertUnprocessable()
            ->assertJsonPath('message', "{$later->delivery_no} is scheduled for Nov 9, 2026. It can be marked Arrived on that day, not before.");

        DeliveryCrew::create(['date' => '2026-11-07', 'salesman_id' => $this->salesman->id, 'driver_id' => $this->driver->id]);
        Sanctum::actingAs($this->salesman);
        $this->postJson("/api/my-route/deliveries/{$later->id}/arrive", ['latitude' => 14.8101, 'longitude' => 121.0501])->assertUnprocessable();
        $this->assertDatabaseCount('delivery_arrivals', 0);
    }

    public function test_salesman_cannot_confirm_a_later_day_delivery(): void
    {
        $this->at('2026-11-07 10:00');
        $later = $this->delivery('2026-11-08', 'in_transit');

        $this->confirm($later)
            ->assertUnprocessable()
            ->assertJsonPath('message', "{$later->delivery_no} is scheduled for Nov 8, 2026. It can be confirmed on that day, not before.");
        $this->assertSame('in_transit', $later->fresh()->status);
        $this->assertDatabaseCount('delivery_proofs', 0);
        $this->assertDatabaseCount('receipts', 0);
    }

    public function test_todays_delivery_is_still_confirmed(): void
    {
        $this->at('2026-11-07 10:00');
        $today = $this->delivery('2026-11-07', 'in_transit');

        $this->confirm($today)->assertSuccessful();
        $this->assertSame('delivered', $today->fresh()->status);
        $this->assertDatabaseCount('receipts', 1);
    }
}
