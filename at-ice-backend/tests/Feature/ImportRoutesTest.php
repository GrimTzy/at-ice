<?php

namespace Tests\Feature;

use App\Console\Commands\ImportRoutes;
use App\Models\Customer;
use App\Models\DeliveryRoute;
use App\Models\ReferencePrice;
use App\Models\RouteStop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// php artisan routes:import routes.csv (client, 2026-11-10): routes, stores in
// stop order, mall tenants as separate customers sharing one stop, and a
// portal login per customer with a dummy .invalid email. Passwords go to a
// private CSV only; no email is sent.
class ImportRoutesTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Mail::fake();
        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'route-import-'.uniqid();
        mkdir($this->dir);
        file_put_contents($this->dir.'/routes.csv', implode("\n", [
            'route,stop_order,store_name,address,lat,lng,km_from_previous',
            'A,1,Alfamart Purok III Graceville SJDM,"Brgy Graceville, San Jose del Monte, Bulacan",14.787419,121.060819,4.36',
            'A,2,Alfamart Gov. F Halili Gaya-Gaya SJDM,"Governor Fortunato Halili Avenue, Brgy Gaya-Gaya, San Jose del Monte, Bulacan",14.794973,121.052335,1.24',
            'D,1,SM City San Jose del Monte (3 tenants),"SM City San Jose del Monte, Tungkong Mangga, San Jose del Monte, Bulacan",14.786069,121.074641,',
            'D,2,Alfamart Bigte Norzagaray,"Brgy Bigte, Norzagaray, Bulacan",14.857169,121.085565,2.23',
        ])."\n");
        file_put_contents($this->dir.'/stores.csv', implode("\n", [
            'name,address,stop',
            'Savory,"SM City San Jose del Monte, Tungkong Mangga, San Jose del Monte, Bulacan",SM City San Jose del Monte',
            "Max's,\"SM City San Jose del Monte, Tungkong Mangga, San Jose del Monte, Bulacan\",SM City San Jose del Monte",
            'Zagu,"SM City San Jose del Monte, Tungkong Mangga, San Jose del Monte, Bulacan",SM City San Jose del Monte',
            'Alfamart Bigte Norzagaray,"Brgy Bigte, Norzagaray, Bulacan",Alfamart Bigte Norzagaray',
        ])."\n");
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*'));
        rmdir($this->dir);
        parent::tearDown();
    }

    public function test_a_dry_run_saves_nothing(): void
    {
        $this->artisan('routes:import', ['file' => $this->dir.'/routes.csv', '--dry-run' => true])
            ->expectsOutputToContain('[DRY RUN] 4 route rows, 6 customers')
            ->expectsOutputToContain('Dry run: nothing was saved')
            ->assertSuccessful();

        $this->assertSame(0, Customer::count());
        $this->assertSame(0, User::count());
        $this->assertSame(0, DeliveryRoute::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_the_import_makes_routes_customers_tenants_and_portal_logins(): void
    {
        $this->artisan('routes:import', ['file' => $this->dir.'/routes.csv'])->assertSuccessful();

        $this->assertSame(['A', 'D'], DeliveryRoute::orderBy('code')->pluck('code')->all());
        $this->assertSame(6, Customer::count());

        // the 3 SM tenants share stop 1 of Route D, the Alfamart is stop 2
        $d = DeliveryRoute::where('code', 'D')->first();
        $stops = RouteStop::with('customer')->where('route_id', $d->id)->orderBy('stop_order')->orderBy('id')->get();
        $this->assertSame([1, 1, 1, 2], $stops->pluck('stop_order')->all());
        $this->assertSame('Savory - SM City San Jose del Monte', $stops[0]->customer->name);
        $this->assertSame('SM City San Jose del Monte', $stops[0]->stop_group);
        $this->assertSame('supermarket', $stops[0]->customer->customer_type);
        $this->assertSame('SM', $stops[0]->customer->chain);
        $this->assertSame('convenience_store', $stops[3]->customer->customer_type);

        $halili = Customer::where('name', 'Alfamart Gov. F Halili Gaya-Gaya SJDM')->first();
        $this->assertSame('Governor Fortunato Halili Avenue', $halili->street);
        $this->assertSame('Gaya-Gaya', $halili->barangay);
        $this->assertSame('San Jose del Monte', $halili->city);
        $this->assertSame('Bulacan', $halili->province);
        $this->assertEqualsWithDelta(14.794973, (float) $halili->latitude, 0.000001);
        $this->assertMatchesRegularExpression('/^CUST-ALF-\d{3}$/', $halili->customer_code);
        $this->assertMatchesRegularExpression('/^ALF-SJDM-\d{3}$/', $halili->branch_code);

        // one login each, dummy .invalid email, must change the one-time password
        $logins = User::where('role', 'customer')->get();
        $this->assertCount(6, $logins);
        $this->assertSame(6, $logins->pluck('email')->unique()->count());
        $this->assertTrue($logins->every(fn ($u) => str_ends_with($u->email, '@atice.invalid') && $u->must_change_password));
        $this->assertSame('savory-sm-sjdm@atice.invalid', Customer::where('name', 'like', 'Savory%')->first()->user->email);
        $this->assertSame('alfamart-purok-iii-graceville-sjdm@atice.invalid', Customer::where('name', 'like', 'Alfamart Purok%')->first()->user->email);

        // the passwords are only in the private CSV, and they work
        $files = Storage::disk('local')->files();
        $this->assertCount(1, $files);
        $this->assertMatchesRegularExpression('/^route-import-logins-\d{8}-\d{6}\.csv$/', $files[0]);
        $rows = array_map('str_getcsv', array_filter(explode("\n", Storage::disk('local')->get($files[0]))));
        $this->assertSame(['route', 'stop', 'customer_code', 'customer', 'login_email', 'one_time_password'], $rows[0]);
        $this->assertCount(7, $rows);
        $row = $rows[1];
        $this->assertTrue(Hash::check($row[5], User::where('email', $row[4])->value('password')));

        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    public function test_running_it_again_changes_nothing_and_makes_no_new_logins(): void
    {
        $this->artisan('routes:import', ['file' => $this->dir.'/routes.csv'])->assertSuccessful();
        $this->artisan('routes:import', ['file' => $this->dir.'/routes.csv'])
            ->expectsOutputToContain('No new portal logins were needed.')
            ->assertSuccessful();

        $this->assertSame(6, Customer::count());
        $this->assertSame(6, User::count());
        $this->assertSame(6, RouteStop::count());
        $this->assertCount(1, Storage::disk('local')->files());
    }

    public function test_a_bad_file_saves_nothing(): void
    {
        file_put_contents($this->dir.'/bad.csv', "route,stop_order,store_name\nA,1,Store\n");
        $this->artisan('routes:import', ['file' => $this->dir.'/bad.csv'])
            ->expectsOutputToContain('missing the column(s): address, lat, lng')
            ->assertFailed();

        file_put_contents($this->dir.'/bad.csv', "route,stop_order,store_name,address,lat,lng\nA,1,Robinsons (5 tenants),Somewhere,14.7,121.0\n");
        $this->artisan('routes:import', ['file' => $this->dir.'/bad.csv'])->assertFailed();
        $this->assertSame(0, Customer::count());
    }

    // The SJDM routes shipped with the app (database/data): 49 rows, 48
    // Alfamart branches and the 40 SM tenants (sjdm-stores.csv, found by name).
    public function test_the_shipped_sjdm_file_dry_runs_and_saves_nothing(): void
    {
        $file = base_path('database/data/sjdm-routes.csv');
        $this->assertSame(base_path('database/data').DIRECTORY_SEPARATOR.'sjdm-stores.csv', ImportRoutes::tenantsFileFor($file));
        // store data only: no logins, passwords or emails in the shipped files
        foreach (['sjdm-routes.csv', 'sjdm-stores.csv'] as $name) {
            $text = file_get_contents(base_path("database/data/{$name}"));
            $this->assertStringNotContainsString('@', $text);
            $this->assertDoesNotMatchRegularExpression('/password|login|email/i', $text);
        }

        $this->artisan('routes:import', ['file' => $file, '--dry-run' => true])
            ->expectsOutputToContain('[DRY RUN] 49 route rows, 88 customers')
            ->expectsOutputToContain('Dry run: nothing was saved')
            ->assertSuccessful();

        $this->assertSame(0, Customer::count());
        $this->assertSame(0, User::count());
        $this->assertSame(0, DeliveryRoute::count());
        $this->assertSame(0, RouteStop::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
        Mail::assertNothingSent();
    }

    // Alfamart buys bags only: with the client's reference prices (Alfamart
    // bags only, set by the reference_prices migration) nothing is missing.
    public function test_alfamart_needs_only_a_bags_price(): void
    {
        $this->assertNull(ReferencePrice::for('convenience_store', 'small_sack'));
        $this->assertSame(['bags'], ImportRoutes::productsFor('Alfamart'));

        $this->artisan('routes:import', ['file' => $this->dir.'/routes.csv'])
            ->expectsOutputToContain('Reference prices: set for every product these stores buy (Alfamart: bags only).')
            ->doesntExpectOutputToContain('MISSING REFERENCE PRICES')
            ->assertSuccessful();
    }

    // A missing reference price does not stop the import; it is listed
    // clearly. Alfamart sacks are never listed.
    public function test_missing_reference_prices_are_listed_and_the_import_goes_on(): void
    {
        ReferencePrice::where('customer_type', 'supermarket')->where('product_type', 'big_sack')->update(['unit_price' => null]);
        ReferencePrice::where('customer_type', 'convenience_store')->delete(); // no row at all

        $this->artisan('routes:import', ['file' => $this->dir.'/routes.csv'])
            ->expectsOutputToContain('MISSING REFERENCE PRICES (2)')
            ->expectsOutputToContain('| SM Malls      | Big Sack (50kg) | 3 ')
            ->expectsOutputToContain('| Alfamart      | Bags (5kg)      | 3 ')
            ->doesntExpectOutputToContain('Small Sack')
            ->assertSuccessful();

        $this->assertSame(['A', 'D'], DeliveryRoute::orderBy('code')->pluck('code')->all());
        $this->assertSame(6, Customer::count());
        $this->assertCount(1, Storage::disk('local')->files());
    }

    public function test_address_parts(): void
    {
        $this->assertSame(
            ['street' => 'SM City San Jose del Monte', 'barangay' => 'Tungkong Mangga', 'city' => 'San Jose del Monte', 'province' => 'Bulacan'],
            ImportRoutes::addressParts('SM City San Jose del Monte, Tungkong Mangga, San Jose del Monte, Bulacan')
        );
        $this->assertSame(
            ['street' => 'Palmera Homes', 'barangay' => null, 'city' => 'San Jose del Monte', 'province' => 'Bulacan'],
            ImportRoutes::addressParts('Palmera Homes, San Jose del Monte, Bulacan')
        );
    }
}
