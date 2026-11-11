<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Delivery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// The From-To delivery window (QA review, 2026-11-11): due_from opens it,
// due_at (Deliver by) is the deadline.
class DeliveryWindowTest extends TestCase
{
    use RefreshDatabase;

    public function test_timing_is_early_before_the_window_and_on_time_inside_it(): void
    {
        $d = new Delivery(['status' => 'delivered', 'due_from' => '2026-11-12 06:00:00', 'due_at' => '2026-11-12 08:00:00']);

        $d->delivered_at = '2026-11-12 05:40:00';
        $this->assertSame(['state' => 'early', 'minutes' => 20], $d->timing);

        $d->delivered_at = '2026-11-12 07:15:00';
        $this->assertSame('on_time', $d->timing['state']);

        $d->delivered_at = '2026-11-12 08:30:00';
        $this->assertSame(['state' => 'late', 'minutes' => 30], $d->timing);

        // no window start: Deliver by only, as before
        $d->due_from = null;
        $d->delivered_at = '2026-11-12 05:40:00';
        $this->assertSame('on_time', $d->timing['state']);
    }

    public function test_a_contract_window_must_open_before_its_deliver_by_time(): void
    {
        $this->travelTo(now('Asia/Manila')->setDate(2026, 11, 11)->setTime(9, 0));
        $store = Customer::create(['name' => 'SM City San Jose del Monte', 'customer_code' => 'CUST-SM-001', 'is_active' => true, 'customer_type' => 'supermarket']);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->postJson('/api/delivery-contracts', [
            'name' => $store->name, 'store_ids' => [$store->id], 'start_date' => '2026-11-12', 'end_date' => '2026-11-30',
            'delivery_days' => ['mon', 'wed'], 'delivery_from' => '08:00', 'delivery_time' => '06:00',
            'items' => [['product_type' => 'big_sack', 'quantity' => 20]],
        ])->assertStatus(422)->assertJsonPath('errors.delivery_from.0', 'The window must open before the Deliver by time.');
    }
}
