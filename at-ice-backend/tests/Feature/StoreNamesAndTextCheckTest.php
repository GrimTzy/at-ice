<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Holiday;
use App\Models\User;
use App\Support\TextRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Store and business names allow / and + (owner, 2026-11-12), and every one of
// the 88 SJDM stores now on the live site (database/data) still passes the
// store rule, so each can be saved again. php artisan text:check lists saved
// rows that break the text rules and changes nothing.
class StoreNamesAndTextCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_imported_sjdm_store_name_passes_the_store_rule(): void
    {
        Storage::fake('local');
        Mail::fake();
        $this->artisan('routes:import', ['file' => base_path('database/data/sjdm-routes.csv')])->assertSuccessful();

        $names = Customer::pluck('name');
        $this->assertCount(88, $names);
        foreach ($names as $name) {
            $this->assertTrue(Validator::make(['name' => $name], ['name' => TextRules::store()])->passes(), "{$name} breaks the store rule");
        }
        $this->artisan('text:check')->doesntExpectOutputToContain('| Customer |')->assertSuccessful();
    }

    public function test_store_names_allow_slash_and_plus(): void
    {
        Sanctum::actingAs(User::create(['name' => 'Admin', 'email' => 'admin@atice.cloud', 'password' => 'secret-password', 'role' => 'admin']));
        $payload = [
            'email' => 'store247@gmail.com', 'contact_number' => '09171234567', 'contact_person' => 'Ana Cruz',
            'street' => 'Quirino Highway', 'barangay' => 'Muzon', 'city' => 'San Jose del Monte', 'province' => 'Bulacan',
            'customer_type' => 'dealer', 'latitude' => 14.81, 'longitude' => 121.05,
        ];

        $this->postJson('/api/customers', ['name' => 'Sari-Sari Store 24/7 + Ice'] + $payload)->assertCreated();
        $this->postJson('/api/customers', ['name' => 'Store #1', 'email' => 'store1@gmail.com'] + $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name' => 'Business name can only include letters, numbers, spaces, periods, commas, apostrophes, hyphens, ampersands (&), brackets, slashes (/) and plus signs (+).']);
    }

    public function test_text_check_lists_rows_that_break_the_rules_and_changes_nothing(): void
    {
        Customer::create(['name' => 'Asdfghjklasdfghjklasdfghjkl Store', 'customer_code' => 'CUST-X-1', 'branch_code' => 'X-1', 'is_active' => true]);
        Customer::create(['name' => 'Alfamart Graceville', 'customer_code' => 'CUST-X-2', 'branch_code' => 'X-2', 'is_active' => true, 'street' => str_repeat('Long street ', 12)]);
        Holiday::create(['date' => '2028-05-05', 'year' => 2028, 'name' => 'Company Day <b>', 'type' => 'REGULAR']);

        $this->artisan('text:check')
            ->expectsOutputToContain('field(s) break the text rules')
            ->expectsOutputToContain('A word in it is too long or repeats the same letters.')
            ->expectsOutputToContain('Longer than 120 characters')
            ->expectsOutputToContain('Name can only include')
            ->assertSuccessful();

        $this->assertSame('Asdfghjklasdfghjklasdfghjkl Store', Customer::where('customer_code', 'CUST-X-1')->value('name'));
        $this->assertSame('Company Day <b>', Holiday::where('year', 2028)->value('name'));
    }
}
