<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\ReferencePrice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Alfamart branches buy bags only (client, 2026-11-12). The "no price" check
// on contract save and edit only looks at the products in that contract, so
// an Alfamart contract with bags only needs only a bags price.
class BagsOnlyContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_bags_only_contract_needs_only_a_bags_price(): void
    {
        Sanctum::actingAs(User::create(['name' => 'Admin', 'email' => 'admin@atice.cloud', 'password' => 'secret-password', 'role' => 'admin']));
        ReferencePrice::updateOrCreate(['customer_type' => 'convenience_store', 'product_type' => 'bags'], ['unit_price' => 24]);
        ReferencePrice::where('customer_type', 'convenience_store')->whereIn('product_type', ['small_sack', 'big_sack'])->update(['unit_price' => null]);
        $store = Customer::create(['name' => 'Alfamart Graceville', 'customer_code' => 'CUST-ALF-001', 'branch_code' => 'ALF-1', 'customer_type' => 'convenience_store', 'chain' => 'Alfamart', 'is_active' => true]);

        $body = [
            'name' => 'Alfamart bags', 'store_ids' => [$store->id],
            'start_date' => now(config('atice.timezone'))->toDateString(),
            'end_date' => now(config('atice.timezone'))->addDays(6)->toDateString(),
            'delivery_days' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], 'delivery_time' => '08:00',
            'items' => [['product_type' => 'bags', 'quantity' => 20]],
        ];
        $id = $this->postJson('/api/delivery-contracts', $body)->assertCreated()->json('id');

        // editing it with bags only is fine too
        $this->putJson("/api/delivery-contracts/{$id}", ['items' => [['product_type' => 'bags', 'quantity' => 30]]] + $body)->assertOk();

        // a sack in the contract does need a sack price, and only that one is named
        $res = $this->putJson("/api/delivery-contracts/{$id}", ['items' => [['product_type' => 'bags', 'quantity' => 30], ['product_type' => 'small_sack', 'quantity' => 2]]] + $body)
            ->assertUnprocessable();
        $this->assertSame(['Alfamart Graceville' => ['small_sack']], $res->json('missing_prices'));
    }
}
