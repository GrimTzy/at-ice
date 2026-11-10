<?php

namespace Tests\Feature;

use App\Models\DeliveryNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Waiting deliveries (no driver) and Reschedule (client, 2026-11-10): Admin
// moves them to the next delivery day; the store gets a portal notice.
class RescheduleTest extends TestCase
{
    use RefreshDatabase;
    use RouteFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRoutes();
        Sanctum::actingAs($this->admin);
    }

    private function portalStore(string $name)
    {
        $user = User::create(['name' => $name, 'email' => \Illuminate\Support\Str::slug($name).'@atice.invalid', 'password' => 'secret-password', 'role' => 'customer']);
        $store = $this->store($name);
        $store->update(['user_id' => $user->id]);

        return $store;
    }

    public function test_reschedule_moves_a_waiting_stop_to_the_contracts_next_day_with_the_route_driver(): void
    {
        $rodel = $this->driver('Rodel', null);
        $store = $this->portalStore('Alfamart One');
        $route = $this->route('B', $rodel, [$store]);
        // delivered Mon, Wed, Fri: Wednesday's stop goes to Friday
        $contract = $this->contract([$store], ['bags' => 10], ['mon', 'wed', 'fri'], '2026-11-09', '2026-11-30');
        $d = $this->delivery(null, $store, status: 'queued', route: $route, contract: $contract);
        $d->update(['reassigned_from_driver_id' => $rodel->id]);

        $res = $this->postJson("/api/deliveries/{$d->id}/reschedule")->assertOk();

        $d->refresh();
        $this->assertSame('2026-11-13', $d->scheduledDateString());
        $this->assertSame('2026-11-11', $d->rescheduled_from->toDateString());
        $this->assertSame($rodel->id, $d->driver_id);
        $this->assertSame('pending', $d->status);
        $this->assertNull($d->reassigned_from_driver_id);
        $this->assertSame('2026-11-13 08:00:00', $d->ordered_at->format('Y-m-d H:i:s'));
        $this->assertStringContainsString('moved to Nov 13', $res->json('message'));
        $note = DeliveryNotification::where('delivery_id', $d->id)->where('kind', 'rescheduled')->first();
        $this->assertSame('portal', $note->channel);
        $this->assertSame('Your delivery DEL-0001 for Nov 11 was moved to Nov 13 because its driver was absent.', $note->message);
    }

    public function test_a_delivery_with_no_contract_moves_to_tomorrow_or_the_date_picked(): void
    {
        $store = $this->store('Corner Store');
        $a = $this->delivery(null, $store, status: 'queued');
        $b = $this->delivery(null, $store, status: 'queued');

        $this->postJson("/api/deliveries/{$a->id}/reschedule")->assertOk();
        $this->assertSame('2026-11-12', $a->fresh()->scheduledDateString());
        $this->assertSame('queued', $a->fresh()->status); // the store has no driver that day either

        $this->postJson("/api/deliveries/{$b->id}/reschedule", ['date' => '2026-11-11'])->assertUnprocessable()->assertJsonValidationErrors('date');
        $this->postJson("/api/deliveries/{$b->id}/reschedule", ['date' => '2026-11-16'])->assertOk();
        $this->assertSame('2026-11-16', $b->fresh()->scheduledDateString());
    }

    public function test_only_a_waiting_delivery_can_be_rescheduled(): void
    {
        $ana = $this->driver('Ana');
        $d = $this->delivery($ana, $this->store('Store'));

        $this->postJson("/api/deliveries/{$d->id}/reschedule")->assertUnprocessable();
        $this->assertSame('2026-11-11', $d->fresh()->scheduledDateString());
    }

    public function test_a_whole_waiting_route_is_rescheduled_at_once(): void
    {
        $rodel = $this->driver('Rodel', null);
        $ana = $this->driver('Ana');
        $s1 = $this->store('Store 1');
        $s2 = $this->store('Store 2');
        $route = $this->route('B', $rodel, [$s1, $s2]);
        $w1 = $this->delivery(null, $s1, status: 'queued', route: $route);
        $w2 = $this->delivery(null, $s2, status: 'queued', route: $route);
        $busy = $this->delivery($ana, $s1, date: '2026-11-12');

        $res = $this->postJson('/api/deliveries/reschedule', ['ids' => [$w1->id, $w2->id, $busy->id], 'date' => '2026-11-12'])->assertOk();

        $this->assertSame(2, $res->json('rescheduled'));
        $this->assertSame([$busy->delivery_no], $res->json('skipped'));
        $this->assertSame($rodel->id, $w1->fresh()->driver_id);
        $this->assertSame('2026-11-12', $w2->fresh()->scheduledDateString());
    }

    public function test_the_waiting_list_includes_past_days_and_the_day_reschedule_would_pick(): void
    {
        $store = $this->store('Store');
        $old = $this->delivery(null, $store, date: '2026-11-10', status: 'queued');
        $today = $this->delivery(null, $store, status: 'queued');
        $this->delivery($this->driver('Ana'), $store);

        $rows = collect($this->getJson('/api/deliveries/waiting')->assertOk()->json());

        $this->assertSame([$old->id, $today->id], $rows->pluck('id')->all());
        $this->assertTrue($rows[0]['is_past']);
        $this->assertSame('2026-11-12', $rows[0]['next_delivery_day']);
        $this->assertFalse($rows[1]['is_past']);
        $this->assertSame(2, $this->getJson('/api/nav-counts/admin')->json('/admin/deliveries'));
    }
}
