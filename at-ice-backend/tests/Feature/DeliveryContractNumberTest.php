<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\DeliveryContract;
use App\Models\User;
use App\Support\DriverAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Contract IDs: DC-{customer code}-{YYMM}-{no.}
class DeliveryContractNumberTest extends TestCase
{
    use RefreshDatabase;

    private Customer $tin;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->tin = $this->store('Tindahan ni Bordan', 'TIN', 1);
    }

    private function store(string $name, ?string $invoice, int $n, ?string $chain = null): Customer
    {
        return Customer::create([
            'name' => $name, 'customer_code' => "CUST-{$n}", 'branch_code' => "BR-{$n}",
            'invoice_code' => $invoice, 'chain' => $chain, 'is_active' => true,
        ]);
    }

    private function make(array $storeIds, ?string $chain = null): string
    {
        // a contract is approved when saved, so every store needs a price
        foreach ($storeIds as $id) {
            \App\Models\CustomerPrice::firstOrCreate(['customer_id' => $id, 'product_type' => 'bags'], ['unit_price' => 40]);
        }

        return $this->postJson('/api/delivery-contracts', [
            'name' => 'Weekly run', 'chain' => $chain, 'store_ids' => $storeIds,
            'start_date' => DriverAvailability::today(),
            'end_date' => now(config('atice.timezone'))->addDays(10)->toDateString(),
            'delivery_days' => ['mon'], 'delivery_time' => '08:00',
            'items' => [['product_type' => 'bags', 'quantity' => 5]],
        ])->assertCreated()->json('contract_no');
    }

    private function month(): string
    {
        return now(config('atice.timezone'))->format('ym');
    }

    public function test_one_store_uses_its_invoice_code_and_counts_up(): void
    {
        $this->assertSame("DC-TIN-{$this->month()}-001", $this->make([$this->tin->id]));
        // one running contract per store: a new one is only made after the old one is cancelled
        DeliveryContract::query()->update(['status' => 'cancelled']);
        $this->assertSame("DC-TIN-{$this->month()}-002", $this->make([$this->tin->id]));
    }

    public function test_a_store_gets_one_contract_and_it_can_be_edited(): void
    {
        $this->make([$this->tin->id]);
        $contract = DeliveryContract::firstOrFail();
        $body = [
            'name' => 'Weekly run', 'chain' => null, 'store_ids' => [$this->tin->id],
            'start_date' => DriverAvailability::today(),
            'end_date' => now(config('atice.timezone'))->addDays(10)->toDateString(),
            'delivery_days' => ['mon'], 'delivery_time' => '08:00',
            'items' => [['product_type' => 'bags', 'quantity' => 5]],
        ];
        $this->postJson('/api/delivery-contracts', $body)->assertStatus(422)->assertJsonPath('errors.store_ids.0', fn ($m) => str_contains($m, $contract->contract_no));

        $edit = ['delivery_days' => ['tue', 'thu'], 'delivery_time' => '09:30', 'items' => [['product_type' => 'bags', 'quantity' => 12]]] + $body;
        $this->putJson("/api/delivery-contracts/{$contract->id}", $edit)->assertOk();
        $contract->refresh();
        $this->assertSame(['tue', 'thu'], $contract->delivery_days);
        $this->assertSame(12, $contract->items[0]['quantity']);
        $this->assertSame(1, DeliveryContract::count());
        // upcoming deliveries follow the new days only
        $days = \App\Models\Delivery::where('contract_id', $contract->id)->where('status', 'pending')->get()
            ->map(fn ($d) => strtolower($d->scheduled_date->format('D')))->unique()->sort()->values()->all();
        $this->assertSame(['thu', 'tue'], $days);
    }

    public function test_each_customer_has_its_own_count(): void
    {
        $pg = $this->store('Pure Gold Muzon', 'PG', 2);
        $this->make([$this->tin->id]);
        $this->assertSame("DC-PG-{$this->month()}-001", $this->make([$pg->id]));
    }

    public function test_a_chain_contract_uses_the_chain_initials(): void
    {
        $a = $this->store('SM Fairview', 'SMF', 3, 'SM Supermarket');
        $b = $this->store('SM Muzon', 'SMM', 4, 'SM Supermarket');
        $this->assertSame("DC-SS-{$this->month()}-001", $this->make([$a->id, $b->id], 'SM Supermarket'));
    }

    public function test_stores_of_different_companies_are_multi(): void
    {
        $pg = $this->store('Pure Gold Muzon', 'PG', 2);
        $this->assertSame("DC-MULTI-{$this->month()}-001", $this->make([$this->tin->id, $pg->id]));
    }

    public function test_the_form_is_shown_the_next_id_before_saving(): void
    {
        $this->getJson("/api/delivery-contracts/next-number?store_ids[]={$this->tin->id}")
            ->assertOk()->assertJsonPath('contract_no', "DC-TIN-{$this->month()}-001");
        $this->make([$this->tin->id]);
        $this->getJson("/api/delivery-contracts/next-number?store_ids[]={$this->tin->id}")
            ->assertJsonPath('contract_no', "DC-TIN-{$this->month()}-002");
    }

    public function test_only_admin_sees_the_next_id(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'hr']));
        $this->getJson("/api/delivery-contracts/next-number?store_ids[]={$this->tin->id}")->assertForbidden();
    }

    public function test_a_store_without_an_invoice_code_uses_its_name(): void
    {
        $x = $this->store('Mang Jun Ice Dealer', null, 5);
        $this->assertSame("DC-MJID-{$this->month()}-001", $this->make([$x->id]));
    }
}
