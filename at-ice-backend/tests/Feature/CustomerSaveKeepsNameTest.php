<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Reported 2026-11-12: "Tindahan ni Aling Rosa" became "Tindahan ni Aling Ros"
// after Edit > Save without changing the name. Saving the customer form as the
// page sends it keeps the name exactly; a real change is now in the audit log.
class CustomerSaveKeepsNameTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_a_customer_unchanged_keeps_its_name_exactly(): void
    {
        Sanctum::actingAs(User::create(['name' => 'Admin', 'email' => 'admin@atice.cloud', 'password' => 'secret-password', 'role' => 'admin']));
        $id = $this->postJson('/api/customers', [
            'name' => 'Tindahan ni Aling Rosa', 'email' => 'rosa.lim@gmail.com', 'contact_number' => '09171234567', 'contact_person' => 'Rosa Lim',
            'street' => 'Quirino Highway', 'barangay' => 'Tungkong Mangga', 'city' => 'San Jose del Monte', 'province' => 'Bulacan',
            'customer_type' => 'dealer', 'invoice_code' => 'TAR', 'latitude' => 14.8105, 'longitude' => 121.0602,
        ])->assertCreated()->json('id');

        // the Edit form sends back every field it loaded
        $saved = Customer::findOrFail($id);
        $form = $saved->only(['name', 'block_lot', 'street', 'subdivision', 'barangay', 'city', 'province', 'region', 'barangay_code', 'postal_code',
            'invoice_code', 'contact_person', 'contact_number', 'email', 'customer_type', 'notes', 'latitude', 'longitude']);
        foreach ([1, 2] as $_) {
            $this->putJson("/api/customers/{$id}", $form)->assertOk()->assertJsonPath('name', 'Tindahan ni Aling Rosa');
        }
        $this->assertSame('Tindahan ni Aling Rosa', Customer::find($id)->name);
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'customer.updated')->count(), 'nothing changed, nothing logged');

        // a real rename is logged with the old and new name
        $this->putJson("/api/customers/{$id}", ['name' => 'Tindahan ni Aling Rosa 2'] + $form)->assertOk();
        $log = DB::table('audit_logs')->where('action', 'customer.updated')->sole();
        $this->assertSame(['name' => 'Tindahan ni Aling Rosa'], json_decode($log->before, true));
        $this->assertSame('Tindahan ni Aling Rosa 2', json_decode($log->after, true)['name']);
    }
}
