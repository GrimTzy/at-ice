<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerPrice;
use App\Models\Delivery;
use App\Models\Receipt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DeliveryLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_route_contract_edit_trip_arrivals_and_photo_confirmation_produce_one_invoice(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => 'admin']);
        $driver = $this->timedIn(User::factory()->create(['role' => 'driver']));
        $salesman = $this->timedIn(User::factory()->create(['role' => 'employee', 'sub_role' => 'salesman']));
        $store = Customer::create(['name' => 'Lifecycle branch', 'customer_code' => 'CUST-QA-001', 'branch_code' => 'QA-001', 'invoice_code' => 'QA', 'is_active' => true, 'latitude' => 14.804, 'longitude' => 121.052]);
        CustomerPrice::create(['customer_id' => $store->id, 'product_type' => 'bags', 'unit_price' => 50]);
        $today = now('Asia/Manila')->toDateString();

        Sanctum::actingAs($admin);
        $route = $this->postJson('/api/store-routes', ['name' => 'Lifecycle route', 'store_ids' => [$store->id]])->assertCreated()->json('id');
        $this->postJson('/api/driver-route-assignments', ['driver_id' => $driver->id, 'route_id' => $route, 'effective_from' => $today])->assertSuccessful();
        $payload = ['name' => $store->name, 'store_ids' => [$store->id], 'start_date' => $today, 'end_date' => $today, 'delivery_days' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], 'delivery_time' => '23:59', 'items' => [['product_type' => 'bags', 'quantity' => 10]]];
        $contract = $this->postJson('/api/delivery-contracts', $payload)->assertCreated()->json('id');
        $payload['items'][0]['quantity'] = 12;
        $this->putJson('/api/delivery-contracts/'.$contract, $payload)->assertOk();
        $active = Delivery::where('contract_id', $contract)->where('status', '!=', 'cancelled')->get();
        $this->assertCount(1, $active);
        $delivery = $active->first();
        $this->assertSame($driver->id, $delivery->driver_id);
        $this->assertSame(12, (int) $delivery->items->first()->quantity_ordered);

        Sanctum::actingAs($driver);
        $this->patchJson('/api/driver/deliveries/'.$delivery->id.'/status', ['status' => 'in_transit'])->assertOk();
        $location = ['latitude' => 14.804, 'longitude' => 121.052, 'accuracy' => 10];
        $this->postJson('/api/driver/deliveries/'.$delivery->id.'/arrive', $location)->assertOk();

        Sanctum::actingAs($salesman);
        $this->putJson('/api/my-route/driver', ['driver_id' => $driver->id])->assertOk();
        $this->postJson('/api/my-route/deliveries/'.$delivery->id.'/arrive', $location)->assertOk();
        $jpeg = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=');
        $confirmation = ['delivery_id' => $delivery->id, 'products' => [['product_type' => 'bags', 'quantity' => 5]], 'photo' => UploadedFile::fake()->createWithContent('proof.jpg', $jpeg)];
        $this->post('/api/delivery-confirmation', $confirmation, ['Accept' => 'application/json'])->assertSuccessful();
        $this->assertSame('delivered', $delivery->fresh()->status);
        $this->assertDatabaseCount('delivery_proofs', 1);
        $this->assertDatabaseCount('receipts', 1);
        $receipt = Receipt::first();
        $this->assertSame(250.0, (float) $receipt->total_amount);
        $this->assertDatabaseHas('receipt_items', ['receipt_id' => $receipt->id, 'quantity' => 5, 'quantity_ordered' => 12]);
        // A second confirmation must not create another invoice or photo record.
        $this->post('/api/delivery-confirmation', $confirmation, ['Accept' => 'application/json'])->assertStatus(422);
        $this->assertDatabaseCount('receipts', 1);
        $this->assertDatabaseCount('delivery_proofs', 1);
    }
}
