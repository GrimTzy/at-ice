<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\DeliveryItem;
use App\Models\ReferencePrice;
use App\Models\StoreRoute;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

// Imports the client's fixed routes (2026-11-10). The SJDM routes ship with
// the app (store names, addresses, map pins, route and stop order only):
//   php artisan routes:import database/data/sjdm-routes.csv --dry-run
//   php artisan routes:import database/data/sjdm-routes.csv
//
// routes.csv: route, stop_order, store_name, address, lat, lng. A row whose
// store is a mall listed in the tenants file (--tenants=, else the file next
// to it named like it with "stores" for "routes", e.g. sjdm-stores.csv, else
// stores.csv) becomes one customer per tenant, all sharing that stop (SM City
// San Jose del Monte: 40 tenants). Each new customer gets a Customer Portal
// login with a dummy email (name@atice.invalid, which can never receive
// mail) and a one-time password (must change it on first login). No email
// is sent. The logins and passwords go to a private CSV on the local disk
// (storage/app/private), never to the screen.
//
// Prices: the import sets none. It lists the reference prices (Customer
// Prices) that are missing for what the imported stores buy: Alfamart
// branches buy bags only, never sacks (client, 2026-11-12); the others all
// three. A missing price does not stop the import.
//
// Safe to run again: customers are matched by name, routes by code, and a
// store's stop is updated. --dry-run shows what would happen and saves nothing.
class ImportRoutes extends Command
{
    protected $signature = 'routes:import
        {file : Path to routes.csv}
        {--tenants= : CSV of mall tenants (name,address,stop); default stores.csv next to the file}
        {--dry-run : Show what would be imported without saving anything}';

    protected $description = 'Import routes A to D and their stores (with portal logins) from routes.csv';

    private const EMAIL_DOMAIN = 'atice.invalid';

    // Chains that buy bags only (retail), never small or big sacks (client,
    // 2026-11-12). Every other store may buy all three products.
    public const BAGS_ONLY_CHAINS = ['Alfamart'];

    private const PRODUCTS = ['bags', 'small_sack', 'big_sack'];

    public function handle(): int
    {
        $file = $this->argument('file');
        $tenantsFile = $this->option('tenants') ?: self::tenantsFileFor($file);
        $dry = (bool) $this->option('dry-run');

        try {
            $rows = $this->readCsv($file, ['route', 'stop_order', 'store_name', 'address', 'lat', 'lng']);
            $tenants = is_file($tenantsFile) ? $this->readCsv($tenantsFile, ['name', 'address', 'stop']) : [];
            $entries = $this->plan($rows, $tenants);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(($dry ? '[DRY RUN] ' : '').count($rows).' route rows, '.count($entries).' customers to place on routes.');

        $logins = [];
        $stats = [];
        DB::beginTransaction();
        try {
            Model::withoutEvents(function () use ($entries, &$logins, &$stats) {
                $routes = [];
                $takenEmails = [];
                foreach ($entries as $e) {
                    $code = $e['route'];
                    $routes[$code] ??= StoreRoute::firstOrCreate(['name' => "Route {$code}"]);
                    $stats[$code] ??= ['stops' => [], 'customers' => 0, 'new' => 0, 'existing' => 0, 'logins' => 0];
                    $stats[$code]['stops'][$e['stop_order']] = true;
                    $stats[$code]['customers']++;

                    $customer = Customer::where('name', $e['name'])->first();
                    if ($customer) {
                        $stats[$code]['existing']++;
                        if ($customer->latitude === null && $e['lat'] !== null) {
                            $customer->update(['latitude' => $e['lat'], 'longitude' => $e['lng']]);
                        }
                    } else {
                        $customer = $this->createCustomer($e);
                        $stats[$code]['new']++;
                    }

                    if (! $customer->user_id) {
                        $email = $this->freeEmail($e['email_name'], $takenEmails);
                        $password = Str::random(10); // same as Add Customer
                        $user = User::create([
                            'name' => $customer->name,
                            'email' => $email,
                            'password' => $password,
                            'role' => 'customer',
                            'must_change_password' => true,
                        ]);
                        $customer->update(['user_id' => $user->id] + ($customer->email ? [] : ['email' => $email]));
                        $logins[] = [$code, $e['stop_order'], $customer->customer_code, $customer->name, $email, $password];
                        $stats[$code]['logins']++;
                    }

                    // A store is on one route at most: the file decides which.
                    if ((int) $customer->store_route_id !== (int) $routes[$code]->id) {
                        $customer->forceFill(['store_route_id' => $routes[$code]->id])->save();
                    }
                }
            });

            if ($dry) {
                DB::rollBack();
            } else {
                DB::commit();
            }
        } catch (Throwable $e) {
            DB::rollBack();
            $this->error('Nothing was saved: '.$e->getMessage());

            return self::FAILURE;
        }

        ksort($stats);
        $this->table(['Route', 'Stops', 'Customers', 'New', 'Already there', 'Logins made'], collect($stats)->map(fn ($s, $code) => [
            $code, count($s['stops']), $s['customers'], $s['new'], $s['existing'], $s['logins'],
        ])->values()->all());

        $this->checkReferencePrices($entries);

        if ($dry) {
            $this->warn('Dry run: nothing was saved and no logins were made. Run again without --dry-run to import.');

            return self::SUCCESS;
        }

        if ($logins) {
            $path = 'route-import-logins-'.now(config('atice.timezone'))->format('Ymd-His').'.csv';
            $out = fopen('php://temp', 'r+');
            fputcsv($out, ['route', 'stop', 'customer_code', 'customer', 'login_email', 'one_time_password']);
            foreach ($logins as $row) {
                fputcsv($out, $row);
            }
            rewind($out);
            Storage::disk('local')->put($path, stream_get_contents($out));
            fclose($out);
            $this->info(count($logins).' portal logins made. Emails and one-time passwords are in '.Storage::disk('local')->path($path).' (private; not emailed).');
        } else {
            $this->info('No new portal logins were needed.');
        }

        Audit::log('routes.imported', null, null, ['file' => basename($file), 'customers' => count($entries), 'logins' => count($logins)]);
        $noDriver = StoreRoute::whereNull('driver_id')->orderBy('name')->pluck('name')->all();
        if ($noDriver) {
            $this->warn('No Driver yet for '.implode(', ', $noDriver).'. Admin gives each route a Driver in Deliveries > Driver Stores.');
        }

        return self::SUCCESS;
    }

    // sjdm-routes.csv -> sjdm-stores.csv next to it when there is one, else
    // stores.csv next to it.
    public static function tenantsFileFor(string $file): string
    {
        $dir = dirname($file).DIRECTORY_SEPARATOR;
        $base = basename($file);
        if (stripos($base, 'routes') !== false) {
            $named = $dir.str_ireplace('routes', 'stores', $base);
            if (is_file($named)) {
                return $named;
            }
        }

        return $dir.'stores.csv';
    }

    // The products a store buys: bags only for Alfamart, else all three.
    public static function productsFor(?string $chain): array
    {
        return in_array($chain, self::BAGS_ONLY_CHAINS, true) ? ['bags'] : self::PRODUCTS;
    }

    // Header row => list of rows keyed by the header, after checking the
    // required columns are there.
    private function readCsv(string $path, array $required): array
    {
        if (! is_file($path)) {
            throw new \RuntimeException("File not found: {$path}");
        }
        $h = fopen($path, 'r');
        $header = array_map(fn ($c) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $c))), fgetcsv($h) ?: []);
        if ($missing = array_diff($required, $header)) {
            throw new \RuntimeException(basename($path).' is missing the column(s): '.implode(', ', $missing));
        }
        $rows = [];
        while (($r = fgetcsv($h)) !== false) {
            if (count(array_filter($r, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }
            $rows[] = array_combine($header, array_pad(array_map('trim', $r), count($header), ''));
        }
        fclose($h);

        return $rows;
    }

    // One entry per customer: the route rows, with each mall row expanded to
    // its tenants (same stop).
    private function plan(array $rows, array $tenants): array
    {
        // only tenants of a shared stop (a store listed as its own stop is not one)
        $byStop = collect($tenants)
            ->filter(fn ($t) => $t['stop'] !== '' && mb_strtolower($t['name']) !== mb_strtolower($t['stop']))
            ->groupBy('stop');
        $entries = [];
        $names = [];
        foreach ($rows as $i => $r) {
            $line = $i + 2;
            $route = strtoupper($r['route']);
            if (! preg_match('/^[A-Z0-9-]{1,10}$/', $route) || ! ctype_digit($r['stop_order']) || $r['store_name'] === '') {
                throw new \RuntimeException("routes.csv line {$line}: needs a route code, a stop number and a store name.");
            }
            $lat = $r['lat'] === '' ? null : (float) $r['lat'];
            $lng = $r['lng'] === '' ? null : (float) $r['lng'];
            if ($lat !== null && ($lat < -90 || $lat > 90 || $lng === null || $lng < -180 || $lng > 180)) {
                throw new \RuntimeException("routes.csv line {$line}: the map pin is not valid.");
            }
            $base = ['route' => $route, 'stop_order' => (int) $r['stop_order'], 'address' => $r['address'], 'lat' => $lat, 'lng' => $lng];

            $mall = $byStop->keys()->first(fn ($stop) => str_starts_with(mb_strtolower($r['store_name']), mb_strtolower($stop)));
            if ($mall !== null) {
                foreach ($byStop[$mall] as $t) {
                    $entries[] = $base + [
                        'name' => "{$t['name']} - {$mall}",
                        'email_name' => Str::slug($t['name']).'-'.Str::slug(self::initials($mall)),
                        'stop_group' => $mall,
                        'customer_type' => 'supermarket', // mall tenants pay the SM Malls prices
                        'chain' => Customer::knownChain(null, $mall) ?? $mall,
                        'address' => $t['address'] ?: $r['address'],
                    ];
                }

                continue;
            }
            if (preg_match('/\(\d+ tenants\)/i', $r['store_name'])) {
                throw new \RuntimeException("routes.csv line {$line}: {$r['store_name']} has tenants, but none are listed in the tenants file.");
            }
            $entries[] = $base + [
                'name' => $r['store_name'],
                'email_name' => Str::slug($r['store_name']),
                'stop_group' => null,
                'customer_type' => Customer::guessType(null, $r['store_name']),
                'chain' => Customer::knownChain(null, $r['store_name']),
            ];
        }

        foreach ($entries as $e) {
            if (isset($names[mb_strtolower($e['name'])])) {
                throw new \RuntimeException("{$e['name']} is listed twice.");
            }
            $names[mb_strtolower($e['name'])] = true;
        }

        return $entries;
    }

    // "SM City San Jose del Monte" -> "sm-sjdm" style short form for emails
    private static function initials(string $mall): string
    {
        $words = preg_split('/\s+/', trim($mall));
        $first = array_shift($words);
        $rest = implode('', array_map(fn ($w) => $w === 'City' ? '' : mb_substr($w, 0, 1), $words));

        return $first.' '.$rest;
    }

    private function createCustomer(array $e): Customer
    {
        $parts = self::addressParts($e['address']);

        return Customer::create([
            'name' => $e['name'],
            'customer_type' => $e['customer_type'],
            'chain' => $e['chain'],
            'invoice_code' => Customer::invoiceCodeFor($e['name']),
            'address' => $e['address'],
            'street' => $parts['street'],
            'barangay' => $parts['barangay'],
            'city' => $parts['city'],
            'province' => $parts['province'],
            'latitude' => $e['lat'],
            'longitude' => $e['lng'],
            'is_active' => true,
            'notes' => 'Imported from routes.csv (Route '.$e['route'].', stop '.$e['stop_order'].').',
        ] + Customer::generateCodes($e['name'], $parts['city'] ?? ''));
    }

    // "Governor F. Halili Ave, Brgy Gaya-Gaya, San Jose del Monte, Bulacan"
    // -> street, barangay, city, province (last two parts are city and province)
    public static function addressParts(string $address): array
    {
        $parts = array_values(array_filter(array_map('trim', explode(',', $address)), fn ($p) => $p !== ''));
        $province = count($parts) >= 2 ? array_pop($parts) : null;
        $city = count($parts) >= 1 ? array_pop($parts) : null;
        $barangay = null;
        foreach ($parts as $i => $p) {
            if (preg_match('/^(brgy\.?|barangay)\s+(.+)$/i', $p, $m)) {
                $barangay = $m[2];
                unset($parts[$i]);
            }
        }
        if ($barangay === null && count($parts) >= 2) {
            $barangay = array_pop($parts);
        }

        return [
            'street' => $parts ? implode(', ', $parts) : null,
            'barangay' => $barangay,
            'city' => $city,
            'province' => $province,
        ];
    }

    // name@atice.invalid, with -2, -3 ... when taken
    private function freeEmail(string $name, array &$taken): string
    {
        $name = Str::limit($name ?: 'customer', 50, '');
        for ($n = 1; ; $n++) {
            $email = ($n === 1 ? $name : "{$name}-{$n}").'@'.self::EMAIL_DOMAIN;
            if (! isset($taken[$email]) && ! User::where('email', $email)->exists()) {
                $taken[$email] = true;

                return $email;
            }
        }
    }

    // The reference prices (per customer type) missing for what the imported
    // stores buy: bags only for Alfamart, all three for the others. A
    // contract for a store needs a price for each product in it, so Admin
    // sets these in Customer Prices first. The import itself goes on.
    private function checkReferencePrices(array $entries): void
    {
        $table = ReferencePrice::table();
        $missing = [];
        foreach ($entries as $e) {
            foreach (self::productsFor($e['chain']) as $product) {
                if (($table[$e['customer_type']][$product] ?? null) === null) {
                    $missing[$e['customer_type']][$product] = ($missing[$e['customer_type']][$product] ?? 0) + 1;
                }
            }
        }

        if (! $missing) {
            $this->info('Reference prices: set for every product these stores buy (Alfamart: bags only).');

            return;
        }
        $rows = [];
        foreach ($missing as $type => $products) {
            foreach (self::PRODUCTS as $product) {
                if (isset($products[$product])) {
                    $rows[] = [Customer::TYPES[$type] ?? $type, DeliveryItem::PRODUCT_TYPES[$product] ?? $product, $products[$product]];
                }
            }
        }
        $this->warn('MISSING REFERENCE PRICES ('.count($rows).'). The routes and customers are imported anyway; set these in Customer Prices > Reference prices before saving contracts for these stores:');
        $this->table(['Customer type', 'Product', 'Imported stores that need it'], $rows);
    }
}
