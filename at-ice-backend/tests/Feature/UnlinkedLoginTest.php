<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// A login with no employee or customer record loads its own pages with empty
// data, so the browser console stays free of 404s. Saving is still refused.
class UnlinkedLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_driver_with_no_employee_record_gets_empty_leave_data(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'driver']));

        $this->getJson('/api/my-leave-requests')->assertOk()->assertExactJson([]);
        $this->getJson('/api/my-leave-credits')->assertOk()->assertJson(['employee_linked' => false]);
        $this->postJson('/api/my-leave-requests', ['reason' => 'Test'])->assertNotFound();
    }

    public function test_a_customer_login_with_no_customer_record_gets_empty_pages(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'customer']));

        $this->getJson('/api/my-profile')->assertOk()->assertJson(['customer_linked' => false]);
        $this->getJson('/api/my-prices')->assertOk()->assertJson(['customer_linked' => false]);
        foreach (['my-delivery-notices', 'my-delivery-history', 'my-receipts', 'my-delivery-proofs', 'my-support-messages'] as $list) {
            $this->getJson("/api/{$list}")->assertOk()->assertExactJson([]);
        }
        $this->postJson('/api/my-support-messages', ['topic' => 'other', 'message' => 'Hello there'])->assertNotFound();
    }
}
