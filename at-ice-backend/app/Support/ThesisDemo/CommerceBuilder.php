<?php

namespace App\Support\ThesisDemo;

use App\Models\CustomerPrice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

// New customers only (existing customers are never touched), their prices,
// contracts, and the connected delivery history: loading count, crew,
// arrivals, Salesman proof, invoice, payment and payment proofs, plus
// reassigned, cancelled, short, top-up and adjusted deliveries, upcoming
// deliveries with no driver (Admin assigns one live), and support tickets.
// Quantities, prices, invoice totals and payments agree by construction.
class CommerceBuilder
{
    private int $deliveryNo;

    private array $price = []; // customer key => product => price

    private array $deliveries = []; // list of built delivered rows for later use

    public function __construct(private Context $c, private SampleFiles $files) {}

    // key => [name, type, chain, contact, emailLocal|null, mop, lat, lng, barangay, own prices|null, createdDaysAgo|null]
    public static function customers(): array
    {
        return [
            'K1' => ['Tindahan ni Aling Rosa', 'dealer', null, 'Rosa Lim', 'rosa.lim', 'cash', 14.8105, 121.0602, 'Tungkong Mangga', null, null],
            'K2' => ['Mang Berto Ice Dealer', 'dealer', null, 'Roberto Cruz', 'berto.cruz', 'gcash', 14.7925, 121.0400, 'Muzon', ['bags' => 48, 'small_sack' => 70, 'big_sack' => 115], null],
            'K3a' => ['Nuevo Mart Muzon', 'supermarket', 'Nuevo Mart', 'Store Manager', null, 'check', 14.7958, 121.0368, 'Muzon', ['bags' => 26, 'small_sack' => 155, 'big_sack' => 255], null],
            'K3b' => ['Nuevo Mart Sapang Palay', 'supermarket', 'Nuevo Mart', 'Store Manager', null, 'check', 14.8320, 121.0490, 'Sapang Palay', ['bags' => 26, 'small_sack' => 155, 'big_sack' => 255], null],
            'K4' => ['Kanto Express Graceville', 'convenience_store', 'Kanto Express', 'Lea Santos', 'lea.santos', 'bank_transfer', 14.7838, 121.0465, 'Graceville', ['small_sack' => 70], null],
            'K5' => ['Bahay Kubo Eatery', 'dealer', null, 'Jun Dizon', null, 'cash', 14.8050, 121.0560, 'Kaypian', ['bags' => 47, 'small_sack' => 68, 'big_sack' => 110], null],
            'K6' => ["Lola Nena's Halo-Halo", 'dealer', null, 'Nena Reyes', 'nena.reyes', 'gcash', 14.8130, 121.0455, 'Gaya-gaya', ['bags' => 46, 'small_sack' => 66], 20],
        ];
    }

    // products a customer orders and the usual quantity range
    private const ORDERS = [
        'dealer' => ['bags' => [20, 60], 'small_sack' => [5, 15], 'big_sack' => [3, 10]],
        'supermarket' => ['big_sack' => [8, 15], 'small_sack' => [10, 20]],
        'convenience_store' => ['bags' => [30, 60], 'small_sack' => [3, 6]],
    ];

    public function build(): void
    {
        $this->customersAndPrices();
        $contracts = $this->contracts();
        $this->assignments();
        $this->history($contracts['approved']);
        $this->upcoming();
        $this->adjustmentRequests();
        $this->paymentProofs();
        $this->tickets();
    }

    private function customersAndPrices(): void
    {
        $c = $this->c;
        foreach (self::customers() as $k => [$name, $type, $chain, $contact, $mail, $mop, $lat, $lng, $brgy, $own, $ago]) {
            $created = $ago ? $c->asOf->subDays($ago)->toDateString() : $c->ended[0]['start'];
            $createdAt = $c->stamp($created, '09:30:00');
            $email = $mail ? "{$mail}@{$c->domain}" : null;
            if (! ($id = $c->m->id("customer:{$k}"))) {
                $userId = null;
                if ($email) {
                    $pw = Str::password(16, symbols: false);
                    $userId = $c->m->insert("custuser:{$k}", 'users', [
                        'name' => $name, 'email' => $email, 'role' => 'customer', 'sub_role' => null, 'is_active' => true,
                        'password' => Hash::make($pw), 'must_change_password' => false, 'created_at' => $createdAt, 'updated_at' => $createdAt,
                    ]);
                    $c->credentials[$k] = [$email, $pw];
                }
                [$code, $branch] = $this->codes($name);
                $id = $c->m->insert("customer:{$k}", 'customers', [
                    'user_id' => $userId,
                    'customer_code' => $code,
                    'name' => $name,
                    'customer_type' => $type,
                    'chain' => $chain,
                    'branch_code' => $branch,
                    'invoice_code' => $this->invoiceCode($name),
                    'street' => 'Purok '.Context::pick($k, 1, 6).' Main Road',
                    'barangay' => $brgy,
                    'city' => 'San Jose del Monte',
                    'province' => 'Bulacan',
                    'region' => 'Region III (Central Luzon)',
                    'address' => 'Purok '.Context::pick($k, 1, 6)." Main Road, {$brgy}, San Jose del Monte, Bulacan",
                    'latitude' => $lat,
                    'longitude' => $lng,
                    'contact_person' => $contact,
                    'contact_number' => null,
                    'email' => $email,
                    'mop' => $mop,
                    'is_active' => true,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ]);
            }
            $c->cust[$k] = $id;

            // Own prices where defined, or where the type has no reference price.
            foreach (array_keys(self::ORDERS[$type]) as $product) {
                $value = $own[$product] ?? null;
                if ($value === null && CustomerPrice::priceFor($id, $product) === null) {
                    $value = ['bags' => 45, 'small_sack' => 65, 'big_sack' => 108][$product];
                }
                if ($value !== null) {
                    $c->m->insert("price:{$k}:{$product}", 'customer_prices', [
                        'customer_id' => $id, 'product_type' => $product, 'unit_price' => $value,
                        'created_at' => $createdAt, 'updated_at' => $createdAt,
                    ]);
                }
                $this->price[$k][$product] = (float) CustomerPrice::priceFor($id, $product);
            }
        }
    }

    private function contracts(): array
    {
        $c = $this->c;
        $next = (int) DB::table('delivery_contracts')->where('contract_no', 'like', 'C-%')->pluck('contract_no')->map(fn ($n) => (int) substr($n, 2))->max();
        $items = [['product_type' => 'big_sack', 'quantity' => 10], ['product_type' => 'small_sack', 'quantity' => 15]];
        $defs = [
            'approved' => ['Nuevo Mart weekday supply', 'Nuevo Mart', $c->paidFromEnd(6)['start'], $c->paidFromEnd(4)['end'], ['mon', 'wed', 'fri'], ['K3a', 'K3b'], 'approved', $items],
            'pending' => ['Nuevo Mart November supply', 'Nuevo Mart', $c->asOf->addDays(3)->toDateString(), $c->asOf->addDays(30)->toDateString(), ['mon', 'wed', 'fri'], ['K3a', 'K3b'], 'pending', $items],
            'cancelled' => ['Kanto Express trial run', 'Kanto Express', $c->asOf->addDays(10)->toDateString(), $c->asOf->addDays(40)->toDateString(), ['tue', 'thu'], ['K4'], 'cancelled', [['product_type' => 'bags', 'quantity' => 40]]],
        ];
        $out = [];
        foreach ($defs as $k => [$name, $chain, $start, $end, $days, $stores, $status, $its]) {
            $made = $k === 'approved' ? $c->date($start)->subDays(5)->toDateString() : $c->asOf->subDays(2)->toDateString();
            $id = $c->m->id("contract:{$k}");
            if (! $id) {
                $id = $c->m->insert("contract:{$k}", 'delivery_contracts', [
                    'contract_no' => 'C-'.str_pad((string) ++$next, 4, '0', STR_PAD_LEFT),
                    'name' => $name, 'chain' => $chain, 'start_date' => $start, 'end_date' => $end,
                    'delivery_days' => json_encode($days), 'delivery_time' => '07:30:00', 'items' => json_encode($its),
                    'status' => $status, 'notes' => null, 'created_by' => $c->adminUserId,
                    'approved_by' => $status === 'approved' ? $c->adminUserId : null,
                    'approved_at' => $status === 'approved' ? $c->stamp($c->date($made)->addDay()->toDateString(), '11:00:00') : null,
                    'created_at' => $c->stamp($made, '15:00:00'), 'updated_at' => $c->stamp($made, '15:00:00'),
                ]);
                foreach ($stores as $s) {
                    $c->m->insert("contractstore:{$k}:{$s}", 'delivery_contract_stores', ['delivery_contract_id' => $id, 'customer_id' => $c->cust[$s]]);
                }
            }
            $out[$k] = ['id' => $id, 'start' => $start, 'end' => $end, 'days' => $days, 'items' => $its,
                'no' => DB::table('delivery_contracts')->where('id', $id)->value('contract_no')];
        }

        return $out;
    }

    // Store assignments that ended yesterday: kept as history, so no future
    // delivery (e.g. from approving the pending contract live) goes to a
    // fictional Driver, who would never time in.
    private function assignments(): void
    {
        $c = $this->c;
        foreach (['E05' => ['K1', 'K2', 'K5'], 'E06' => ['K4', 'K6'], 'E19' => ['K3a', 'K3b']] as $drv => $stores) {
            foreach ($stores as $s) {
                $c->m->insert("assign:{$drv}:{$s}", 'driver_store_assignments', [
                    'driver_id' => $c->user[$drv], 'store_id' => $c->cust[$s],
                    'effective_from' => max($c->ended[0]['start'], DB::table('customers')->where('id', $c->cust[$s])->value(DB::raw('DATE(created_at)'))),
                    'effective_to' => $c->asOf->subDay()->toDateString(),
                    'created_by' => $c->adminUserId, 'created_at' => $c->stamp($c->ended[0]['start'], '08:00:00'), 'updated_at' => $c->stamp($c->ended[0]['start'], '08:00:00'),
                ]);
            }
        }
    }

    private function present(string $code, string $ymd): bool
    {
        return $this->c->m->has("att:{$code}:{$ymd}");
    }

    private function history(array $contract): void
    {
        $c = $this->c;
        $this->deliveryNo = (int) DB::table('deliveries')->where('delivery_no', 'like', 'DEL-%')->pluck('delivery_no')
            ->filter(fn ($n) => preg_match('/^DEL-\d{4,}$/', $n))->map(fn ($n) => (int) substr($n, 4))->max();
        $crews = [['E05', ['E07']], ['E06', ['E10', 'E13']], ['E19', ['E13', 'E17', 'E10']]];
        $rotation = ['K1', 'K2', 'K4', 'K5', 'K6', 'K3a', 'K3b'];
        $from = $c->ended[0]['start'];
        $until = $c->asOf->subDay()->toDateString();
        $i = 0;
        $specials = $this->specialDays();

        for ($d = $c->date($from); $d->toDateString() <= $until; $d = $d->addDay()) {
            $ymd = $d->toDateString();
            if (! $c->isWorkDay($ymd) || $c->isHoliday($ymd)) {
                continue;
            }
            $i++;
            $isContractDay = $ymd >= $contract['start'] && $ymd <= $contract['end'] && in_array(strtolower($d->format('D')), $contract['days'], true);

            // stores of each crew today
            $plan = [];
            $taken = [];
            foreach ($crews as $n => [$drv]) {
                $stores = [];
                if ($drv === 'E19' && $isContractDay) {
                    $stores = ['K3a', 'K3b'];
                } else {
                    for ($t = 0; $t < count($rotation) && ! $stores; $t++) {
                        $k = $rotation[($i * 3 + $n + $t) % count($rotation)];
                        if (($isContractDay && in_array($k, ['K3a', 'K3b'], true)) || isset($taken[$k])) {
                            continue;
                        }
                        if ($k === 'K6' && $ymd <= $c->asOf->subDays(19)->toDateString()) {
                            continue; // not a customer yet
                        }
                        $stores[] = $k;
                    }
                }
                foreach ($stores as $k) {
                    $taken[$k] = true;
                }
                $plan[$n] = $stores;
            }

            // salesman per crew: the first one present and not already riding
            $usedSales = [];
            $crewOf = [];
            foreach ($crews as $n => [$drv, $sales]) {
                foreach ($sales as $s) {
                    if (! isset($usedSales[$s]) && $this->present($s, $ymd)) {
                        $crewOf[$n] = [$drv, $s];
                        $usedSales[$s] = true;
                        break;
                    }
                }
            }

            foreach ($plan as $n => $stores) {
                [$drv] = $crews[$n];
                $reFrom = null;
                if (! $this->present($drv, $ymd) || ! isset($crewOf[$n])) {
                    // Driver off: stores go to another crew that is out today (reassigned)
                    $alt = collect($crewOf)->keys()->first(fn ($x) => $x !== $n && $this->present($crews[$x][0], $ymd));
                    if ($alt === null) {
                        continue;
                    }
                    $reFrom = $this->present($drv, $ymd) ? null : $drv;
                    $crew = $crewOf[$alt];
                } else {
                    $crew = $crewOf[$n];
                }
                foreach ($stores as $stop => $k) {
                    $this->deliver($ymd, $k, $crew[0], $crew[1], $stop + ($reFrom ? 1 : 0), $reFrom, $isContractDay && in_array($k, ['K3a', 'K3b'], true) ? $contract : null, $this->special($specials, $ymd, $k));
                }
            }
            foreach ($crewOf as [$drv, $s]) {
                $c->m->insert("crew:{$ymd}:{$s}", 'delivery_crews', [
                    'date' => $ymd, 'salesman_id' => $c->user[$s], 'driver_id' => $c->user[$drv], 'chosen_by' => 'system',
                    'created_at' => $c->stamp($ymd, '08:40:00'), 'updated_at' => $c->stamp($ymd, '08:40:00'),
                ]);
            }
        }
    }

    // A few deliveries get a special outcome: the first delivery of that store
    // in that period. period start => store => kind
    private array $usedSpecial = [];

    private function specialDays(): array
    {
        $c = $this->c;
        $map = [];
        foreach ([[5, 'K2', 'short'], [2, 'K4', 'short'], [3, 'K1', 'cancel_pending'], [1, 'K5', 'cancel_transit'],
            [1, 'K4', 'top_up'], [2, 'K3a', 'admin_add'], [1, 'K2', 'request_applied']] as [$n, $store, $kind]) {
            $map[$c->paidFromEnd($n)['start']][$store] = $kind;
        }

        return $map;
    }

    private function special(array $map, string $ymd, string $k): ?string
    {
        foreach ($map as $start => $stores) {
            $end = $this->c->date($start)->addDays(13)->toDateString();
            if ($ymd >= $start && $ymd <= $end && isset($stores[$k]) && ! isset($this->usedSpecial[$start][$k])) {
                $this->usedSpecial[$start][$k] = true;

                return $stores[$k];
            }
        }

        return null;
    }

    // Customer code and branch code, same pattern as CustomerController
    // (CUST-TIN-001, TIN-SJDM-001), one above the highest in use.
    private function codes(string $name): array
    {
        $first = preg_split('/\s+/', trim($name))[0] ?? '';
        $prefix = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $first), 0, 3)) ?: 'CUS';
        $next = function (string $column, string $stem) {
            $max = DB::table('customers')->where($column, 'like', $stem.'%')->pluck($column)
                ->map(fn ($v) => preg_match('/^'.preg_quote($stem, '/').'(\d+)$/', $v, $m) ? (int) $m[1] : 0)->max() ?? 0;

            return $stem.str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
        };

        return [$next('customer_code', "CUST-{$prefix}-"), $next('branch_code', "{$prefix}-SJDM-")];
    }

    // 2-5 capital letters, unique: the initials, then longer variants.
    private function invoiceCode(string $name): string
    {
        $words = array_values(array_filter(preg_split('/\s+/', preg_replace('/[^A-Za-z ]/', '', $name))));
        $initials = strtoupper(implode('', array_map(fn ($w) => $w[0], $words)));
        $letters = strtoupper(preg_replace('/[^A-Za-z]/', '', $name));
        $tries = [substr($initials, 0, 5)];
        for ($i = 1; $i < strlen($letters); $i++) {
            $tries[] = substr(substr($initials, 0, 3).substr($letters, $i), 0, 5);
        }
        foreach ($tries as $t) {
            if (strlen($t) >= 2 && ! DB::table('customers')->where('invoice_code', $t)->exists()) {
                return $t;
            }
        }

        return 'X'.strtoupper(Str::random(4));
    }

    private function nextNo(): string
    {
        return 'DEL-'.str_pad((string) ++$this->deliveryNo, 4, '0', STR_PAD_LEFT);
    }

    // One delivery with everything that follows it. $special: short | cancel_pending
    // | cancel_transit | top_up | admin_add | request_applied | null
    private function deliver(string $ymd, string $k, string $drv, string $sales, int $stop, ?string $reassignedFrom, ?array $contract, ?string $special, ?int $parentId = null): ?int
    {
        $c = $this->c;
        $key = "delivery:{$ymd}:{$k}".($parentId ? ':topup' : '');
        if ($id = $c->m->id($key)) {
            return $id;
        }
        // Not every store orders every time: the rotation skips a store whose
        // pattern says it is not due (keeps volumes believable), but never a special.
        $type = self::customers()[$k][1];
        $items = [];
        if ($contract) {
            foreach ($contract['items'] as $it) {
                $items[$it['product_type']] = $it['quantity'];
            }
        } elseif ($parentId) {
            $items = ['bags' => 20];
        } else {
            foreach (self::ORDERS[$type] as $product => [$lo, $hi]) {
                if (! isset($this->price[$k][$product]) || $this->price[$k][$product] <= 0) {
                    continue;
                }
                if (count($items) && Context::pick("{$ymd}{$k}{$product}x", 0, 2) === 0) {
                    continue;
                }
                $items[$product] = Context::pick("{$ymd}{$k}{$product}", $lo, $hi);
            }
        }
        $orderedAt = $c->stamp($ymd, $contract ? '07:30:00' : '08:00:00');
        $loadAt = CarbonImmutable::parse("{$ymd} 09:05:00", Context::TZ)->addMinutes($stop * 6);
        $dispatched = CarbonImmutable::parse("{$ymd} 09:35:00", Context::TZ)->addMinutes($stop * 5);
        $deliveredAt = CarbonImmutable::parse("{$ymd} ".($parentId ? '15:10:00' : '10:20:00'), Context::TZ)
            ->addMinutes($stop * 150 + Context::pick("{$ymd}{$k}t", 0, 50));
        $utc = fn (CarbonImmutable $t) => $t->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');

        $status = match ($special) {
            'cancel_pending', 'cancel_transit' => 'cancelled',
            default => 'delivered',
        };
        $id = $c->m->insert($key, 'deliveries', [
            'contract_id' => $contract['id'] ?? null,
            'delivery_no' => $this->nextNo(),
            'driver_id' => $c->user[$drv],
            'reassigned_from_driver_id' => $reassignedFrom ? $c->user[$reassignedFrom] : null,
            'customer_id' => $c->cust[$k],
            'delivery_type' => 'outbound',
            'status' => $status,
            'scheduled_date' => $ymd,
            'ordered_at' => $orderedAt,
            'dispatched_at' => $special === 'cancel_pending' ? null : $utc($dispatched),
            'delivered_at' => $status === 'delivered' ? $utc($deliveredAt) : null,
            'notes' => $contract ? "Contract {$contract['no']}" : ($parentId ? 'Urgent top-up requested by the store' : null),
            'cancel_reason' => match ($special) {
                'cancel_pending' => 'Store closed for the barangay fiesta; customer asked to skip this day.',
                'cancel_transit' => 'Emergency: truck tire blowout on the way. Sacks returned to the plant.',
                default => null,
            },
            'prepared_by' => $c->adminUserId,
            'parent_delivery_id' => $parentId,
            'is_top_up' => (bool) $parentId,
            'created_at' => $orderedAt,
            'updated_at' => $status === 'delivered' ? $utc($deliveredAt) : $utc($dispatched),
        ]);

        // Admin added sacks before loading (DeliveryAdjust "add")
        if ($special === 'admin_add') {
            $p = array_key_first($items);
            $c->m->insert("adj:{$id}", 'delivery_adjustments', [
                'delivery_id' => $id, 'kind' => 'add', 'source' => 'admin', 'reason' => 'Store asked for 3 more sacks for the weekend sale.',
                'changes' => json_encode([['product_type' => $p, 'before' => $items[$p], 'after' => $items[$p] + 3]]),
                'user_id' => $c->adminUserId, 'created_at' => $c->stamp($ymd, '08:20:00'), 'updated_at' => $c->stamp($ymd, '08:20:00'),
            ]);
            $items[$p] += 3;
        }
        // The store asked to reduce, Admin applied it (DeliveryAdjust "reduce")
        if ($special === 'request_applied') {
            $p = array_key_first($items);
            $after = max(1, $items[$p] - 5);
            $adj = $c->m->insert("adj:{$id}", 'delivery_adjustments', [
                'delivery_id' => $id, 'kind' => 'reduce', 'source' => 'customer', 'reason' => 'Rainy day, slow sales expected.',
                'changes' => json_encode([['product_type' => $p, 'before' => $items[$p], 'after' => $after]]),
                'user_id' => $c->adminUserId, 'created_at' => $c->stamp($ymd, '07:50:00'), 'updated_at' => $c->stamp($ymd, '07:50:00'),
            ]);
            $c->m->insert("adjreq:applied:{$id}", 'delivery_adjustment_requests', [
                'delivery_id' => $id, 'customer_id' => $c->cust[$k],
                'items' => json_encode([['product_type' => $p, 'before' => $items[$p], 'after' => $after]]),
                'reason' => 'Rainy day, slow sales expected.', 'status' => 'applied', 'review_note' => null,
                'reviewed_by' => $c->adminUserId, 'reviewed_at' => $c->stamp($ymd, '07:50:00'), 'adjustment_id' => $adj,
                'requested_by' => DB::table('customers')->where('id', $c->cust[$k])->value('user_id'),
                'created_at' => $c->stamp($ymd, '07:10:00'), 'updated_at' => $c->stamp($ymd, '07:50:00'),
            ]);
            $items[$p] = $after;
        }

        $delivered = $items;
        if ($special === 'short') {
            $p = array_key_last($items);
            $delivered[$p] = max(1, $items[$p] - 2);
        }
        foreach ($items as $product => $qty) {
            $c->m->insert("item:{$id}:{$product}", 'delivery_items', [
                'delivery_id' => $id, 'product_type' => $product, 'quantity_ordered' => $qty,
                'quantity_delivered' => $status === 'delivered' ? $delivered[$product] : 0,
                'unit_price' => $this->price[$k][$product], 'created_at' => $orderedAt, 'updated_at' => $orderedAt,
            ]);
        }

        if ($reassignedFrom) {
            $customerUser = DB::table('customers')->where('id', $c->cust[$k])->value('user_id');
            $no = DB::table('deliveries')->where('id', $id)->value('delivery_no');
            $c->m->insert("notice:{$id}", 'delivery_notifications', [
                'customer_id' => $c->cust[$k], 'delivery_id' => $id, 'channel' => $customerUser ? 'portal' : 'none',
                'message' => "Your delivery {$no} today may be delayed: it was moved to another driver.",
                'sent_at' => $c->stamp($ymd, '09:00:00'), 'created_at' => $c->stamp($ymd, '09:00:00'), 'updated_at' => $c->stamp($ymd, '09:00:00'),
            ]);
        }
        if ($special === 'cancel_pending') {
            return $id;
        }

        // Checker's loading count (one per product)
        $checker = collect(['E01', 'E14', 'E12', 'E08', 'E11'])->first(fn ($x) => $this->present($x, $ymd)) ?? 'E01';
        foreach ($items as $product => $qty) {
            $c->m->insert("load:{$id}:{$product}", 'ice_loadings', [
                'delivery_id' => $id, 'product_type' => $product, 'checker_id' => $c->user[$checker], 'quantity_loaded' => $qty,
                'loaded_at' => $loadAt->format('Y-m-d H:i:s'), 'created_at' => $utc($loadAt), 'updated_at' => $utc($loadAt),
            ]);
        }
        if ($status !== 'delivered') {
            return $id;
        }

        // Driver and Salesman pressed Arrived near the store pin (seeded, not a real GPS reading)
        [$lat, $lng] = [self::customers()[$k][6], self::customers()[$k][7]];
        foreach ([[$drv, 'driver', 8], [$sales, 'salesman', 6]] as [$who, $role, $before]) {
            $c->m->insert("arrive:{$id}:{$role}", 'delivery_arrivals', [
                'delivery_id' => $id, 'user_id' => $c->user[$who], 'role' => $role,
                'latitude' => $lat + Context::pick("{$id}{$role}a", -30, 30) / 100000, 'longitude' => $lng + Context::pick("{$id}{$role}b", -30, 30) / 100000,
                'accuracy_m' => Context::pick("{$id}{$role}c", 8, 20), 'distance_m' => Context::pick("{$id}{$role}d", 6, 55),
                'arrived_at' => $utc($deliveredAt->subMinutes($before)), 'created_at' => $utc($deliveredAt->subMinutes($before)), 'updated_at' => $utc($deliveredAt->subMinutes($before)),
            ]);
        }

        $total = 0.0;
        $qtyTotal = 0;
        foreach ($delivered as $product => $q) {
            $total += $q * $this->price[$k][$product];
            $qtyTotal += $q;
        }
        $photo = $this->files->png('proof:'.(crc32($k) % 3), 'public', 'delivery_proofs', ['Delivery proof photo', 'Sample only', 'Fictional thesis data'], [[220, 237, 200], [214, 234, 248], [253, 235, 208]][crc32($k) % 3]);
        $shortNote = $special === 'short' ? 'Two sacks melted in transit; the store accepted the rest.' : null;
        $proof = $c->m->insert("proof:{$id}", 'delivery_proofs', [
            'delivery_id' => $id, 'salesman_id' => $c->user[$sales], 'quantity' => $qtyTotal, 'photo_path' => $photo,
            'confirmed_at' => $utc($deliveredAt), 'notes' => $shortNote, 'created_at' => $utc($deliveredAt), 'updated_at' => $utc($deliveredAt),
        ]);
        $this->receipt($id, $proof, $k, $sales, $delivered, $items, $total, $qtyTotal, $deliveredAt);
        $this->deliveries[] = ['id' => $id, 'k' => $k, 'ymd' => $ymd];

        if ($special === 'top_up') {
            $top = $this->deliver($ymd, $k, $drv, $sales, 0, null, null, null, $id);
            $c->m->insert("adj:{$top}", 'delivery_adjustments', [
                'delivery_id' => $top, 'kind' => 'top_up', 'source' => 'customer', 'reason' => 'Ran out of ice before noon (hot weather).',
                'changes' => json_encode([['product_type' => 'bags', 'before' => 0, 'after' => 20]]),
                'user_id' => DB::table('customers')->where('id', $c->cust[$k])->value('user_id'),
                'created_at' => $c->stamp($ymd, '12:15:00'), 'updated_at' => $c->stamp($ymd, '12:15:00'),
            ]);
        }

        return $id;
    }

    private function receipt(int $deliveryId, int $proofId, string $k, string $sales, array $delivered, array $ordered, float $total, int $qty, CarbonImmutable $at): void
    {
        $c = $this->c;
        $cust = DB::table('customers')->where('id', $c->cust[$k])->first();
        $prefix = ($cust->invoice_code ?: 'INV').'-'.$at->format('Ymd').'-';
        $max = DB::table('receipts')->where('receipt_no', 'like', $prefix.'%')->pluck('receipt_no')->map(fn ($n) => (int) substr($n, strlen($prefix)))->max() ?? 0;
        $age = (int) $c->date($at->toDateString())->diffInDays($c->asOf);
        $mop = self::customers()[$k][5];

        // when it was paid: COD dealers on the spot, others on terms
        $paidAfter = match ($k) {
            'K1', 'K5' => 0,
            'K6' => $age >= 3 ? 1 : null,
            'K2' => $age > 30 ? 7 : null,
            'K3a', 'K3b' => $age > 15 ? 14 : null,
            'K4' => $age > 5 ? 3 : null,
        };
        $status = $paidAfter !== null ? 'paid' : ($age >= \App\Models\Receipt::OVERDUE_DAYS ? 'overdue' : 'unpaid');
        $paidAt = $paidAfter !== null ? $at->addDays($paidAfter)->setTime(14, 0)->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s') : null;
        $created = $at->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');

        $rid = $c->m->insert("receipt:{$deliveryId}", 'receipts', [
            'receipt_no' => $prefix.str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT),
            'delivery_id' => $deliveryId, 'delivery_proof_id' => $proofId, 'customer_id' => $c->cust[$k], 'salesman_id' => $c->user[$sales],
            'quantity' => $qty, 'unit_price' => count($delivered) === 1 ? $this->price[$k][array_key_first($delivered)] : null,
            'total_amount' => round($total, 2), 'payment_status' => $status,
            'payment_method' => $status === 'paid' ? $mop : null, 'paid_at' => $paidAt,
            'created_at' => $created, 'updated_at' => $paidAt ?? $created,
        ]);
        foreach ($delivered as $product => $q) {
            $c->m->insert("ritem:{$rid}:{$product}", 'receipt_items', [
                'receipt_id' => $rid, 'product_type' => $product, 'quantity' => $q, 'quantity_ordered' => $ordered[$product],
                'unit_price' => $this->price[$k][$product], 'line_total' => round($q * $this->price[$k][$product], 2),
                'created_at' => $created, 'updated_at' => $created,
            ]);
        }
    }

    // Upcoming deliveries with no Driver yet: Admin assigns a Driver who has
    // timed in on that day (a group member) during the demo.
    private function upcoming(): void
    {
        $c = $this->c;
        $days = [];
        for ($d = $c->asOf->addDay(); count($days) < 2; $d = $d->addDay()) {
            if ($c->isWorkDay($d->toDateString()) && ! $c->isHoliday($d->toDateString())) {
                $days[] = $d->toDateString();
            }
        }
        foreach ($days as $ymd) {
            foreach (['K1', 'K2', 'K4', 'K6'] as $k) {
                $key = "delivery:{$ymd}:{$k}";
                if ($c->m->has($key)) {
                    continue;
                }
                $made = $c->stamp($c->asOf->subDay()->toDateString(), '16:00:00');
                if (! isset($this->deliveryNo)) {
                    $this->deliveryNo = (int) DB::table('deliveries')->where('delivery_no', 'like', 'DEL-%')->pluck('delivery_no')
                        ->filter(fn ($n) => preg_match('/^DEL-\d{4,}$/', $n))->map(fn ($n) => (int) substr($n, 4))->max();
                }
                $id = $c->m->insert($key, 'deliveries', [
                    'delivery_no' => $this->nextNo(), 'driver_id' => null, 'customer_id' => $c->cust[$k], 'delivery_type' => 'outbound',
                    'status' => 'pending', 'scheduled_date' => $ymd, 'ordered_at' => $c->stamp($ymd, '08:00:00'),
                    'prepared_by' => $c->adminUserId, 'is_top_up' => false, 'created_at' => $made, 'updated_at' => $made,
                ]);
                $type = self::customers()[$k][1];
                foreach (array_slice(self::ORDERS[$type], 0, 2, true) as $product => [$lo, $hi]) {
                    if (! isset($this->price[$k][$product])) {
                        continue;
                    }
                    $c->m->insert("item:{$id}:{$product}", 'delivery_items', [
                        'delivery_id' => $id, 'product_type' => $product, 'quantity_ordered' => Context::pick("{$ymd}{$k}{$product}", $lo, $hi),
                        'quantity_delivered' => 0, 'unit_price' => $this->price[$k][$product], 'created_at' => $made, 'updated_at' => $made,
                    ]);
                }
            }
        }
    }

    // The stored shape of a store's request, the same as DeliveryAdjust::requestChange
    // writes and the Admin and customer pages read: one entry per product,
    // [{product_type, before, after}], where before = sacks on the delivery now
    // and after = sacks the store asks for.
    public static function requestItems(int $deliveryId, array $wanted): array
    {
        $out = [];
        foreach ($wanted as $type => $after) {
            $before = (int) DB::table('delivery_items')->where('delivery_id', $deliveryId)->where('product_type', $type)->sum('quantity_ordered');
            $out[] = ['product_type' => $type, 'before' => $before, 'after' => $after === $before ? $after + 5 : (int) $after];
        }

        return $out;
    }

    // Store requests to change sacks: pending (upcoming), declined and withdrawn (history)
    private function adjustmentRequests(): void
    {
        $c = $this->c;
        $upcomingK2 = DB::table('deliveries')->whereIn('id', $c->m->ids('deliveries'))->where('customer_id', $c->cust['K2'])->where('status', 'pending')->orderBy('scheduled_date')->first();
        $hist = fn (string $k) => collect($this->deliveries)->where('k', $k)->last();
        $rows = [
            ['pending', $upcomingK2?->id, 'K2', ['bags' => 70], 'Big event at the plaza: we need more bags.', null],
            ['declined', $hist('K1')['id'] ?? null, 'K1', ['bags' => 80], 'Expecting many buyers today.', 'The truck was already loaded and out; please request earlier next time.'],
            ['withdrawn', $hist('K6')['id'] ?? null, 'K6', ['small_sack' => 2], 'Fewer customers expected.', null],
        ];
        foreach ($rows as [$status, $deliveryId, $k, $wanted, $reason, $note]) {
            if (! $deliveryId) {
                continue;
            }
            $at = $status === 'pending' ? $c->stamp($c->asOf->toDateString(), '07:05:00') : DB::table('deliveries')->where('id', $deliveryId)->value('ordered_at');
            // declined after the truck had left (as the review note says), never at the same second it was filed
            $dispatched = DB::table('deliveries')->where('id', $deliveryId)->value('dispatched_at');
            $reviewed = $status === 'declined'
                ? CarbonImmutable::parse($dispatched && $dispatched > $at ? $dispatched : $at)->addMinutes(10)->format('Y-m-d H:i:s')
                : null;
            $c->m->insert("adjreq:{$status}", 'delivery_adjustment_requests', [
                'delivery_id' => $deliveryId, 'customer_id' => $c->cust[$k], 'items' => json_encode(self::requestItems($deliveryId, $wanted)), 'reason' => $reason,
                'status' => $status, 'review_note' => $note, 'reviewed_by' => $status === 'declined' ? $c->adminUserId : null,
                'reviewed_at' => $reviewed, 'adjustment_id' => null,
                'requested_by' => DB::table('customers')->where('id', $c->cust[$k])->value('user_id'),
                'created_at' => $at, 'updated_at' => $reviewed ?? $at,
            ]);
        }
    }

    // Mang Berto: one proof approved (invoice paid), one rejected (still
    // overdue), one waiting for Admin (live checkpoint).
    private function paymentProofs(): void
    {
        $c = $this->c;
        $user = DB::table('customers')->where('id', $c->cust['K2'])->value('user_id');
        $r = DB::table('receipts')->whereIn('id', $c->m->ids('receipts'))->where('customer_id', $c->cust['K2']);
        $overdue = (clone $r)->where('payment_status', 'overdue')->orderBy('created_at')->get();
        $unpaid = (clone $r)->where('payment_status', 'unpaid')->orderByDesc('created_at')->first();
        $paid = (clone $r)->where('payment_status', 'paid')->orderByDesc('created_at')->first();
        $file = fn ($tag) => $this->files->png("payproof:{$tag}", 'local', 'payment-proofs', ['GCash receipt', 'Sample only', 'Fictional thesis data'], [255, 243, 205]);
        $rows = [];
        if ($paid) {
            $rows[] = ['approved', $paid, null];
        }
        if ($overdue->count()) {
            $rows[] = ['rejected', $overdue->first(), 'The reference number does not match any GCash transfer we received. Please send the correct screenshot.'];
        }
        if ($unpaid) {
            $rows[] = ['pending', $unpaid, null];
        }
        foreach ($rows as [$status, $rec, $why]) {
            $sent = CarbonImmutable::parse($rec->created_at)->addDays(2);
            $reviewed = $status === 'approved' ? ($rec->paid_at ?? $sent->addDay()) : $sent->addDay();
            $c->m->insert("payproof:{$status}", 'payment_proofs', [
                'receipt_id' => $rec->id, 'customer_id' => $c->cust['K2'], 'submitted_by' => $user, 'file_path' => $file($status),
                'file_disk' => 'local', 'original_name' => "gcash-{$status}.png", 'method' => 'gcash',
                'reference_no' => (string) (9000000000 + Context::pick($status, 100000, 999999)),
                'status' => $status, 'reject_reason' => $why,
                'reviewed_by' => $status === 'pending' ? null : $c->adminUserId,
                'reviewed_at' => $status === 'pending' ? null : $reviewed,
                'created_at' => $sent, 'updated_at' => $status === 'pending' ? $sent : $reviewed,
            ]);
        }
    }

    private function tickets(): void
    {
        $c = $this->c;
        $u = fn (string $k) => DB::table('customers')->where('id', $c->cust[$k])->value('user_id');
        $short = DB::table('deliveries')->whereIn('id', $c->m->ids('deliveries'))->where('customer_id', $c->cust['K4'])
            ->whereNotNull('delivered_at')->whereIn('id', DB::table('delivery_proofs')->whereNotNull('notes')->select('delivery_id'))->value('delivery_no');
        $a = $c->asOf;
        $T = [
            ['t1', 'K1', 'late_delivery', 'low', 'Our ice arrived almost two hours late this morning. Customers were waiting.', 'closed', $a->subDays(40), [['reply', 'Sorry po for the delay. The truck had a flat tire; we have added a spare-tire check before dispatch.'], ['customer', 'Salamat po.']], 'Driver delayed by a flat tire; added a pre-dispatch tire check.'],
            ['t2', 'K1', 'feedback', 'low', 'The new salesman is very polite. Keep it up!', 'resolved', $a->subDays(6), [['reply', 'Thank you po! We will pass this on.']], 'Feedback forwarded to the sales team.'],
            ['t3', 'K2', 'billing', 'medium', 'My invoice shows 3 big sacks more than what we received last week.', 'in_progress', $a->subDays(2), [['reply', 'Checking the delivery proof and loading count now.'], ['note', 'Loading count matches the order; compare with the Salesman proof photo.'], ['customer', 'Please update me today.']], null],
            ['t4', 'K2', 'payment_proof', 'medium', 'I sent my GCash proof two days ago. Please confirm.', 'open', $a->subDays(3), [], null],
            ['t5', 'K4', 'emergency_supply', 'high', 'We ran out of ice before noon. Can you send 20 more bags today?', 'open', CarbonImmutable::parse($a->toDateString().' 07:20:00', Context::TZ), [], null],
            ['t6', 'K4', 'short_delivery', 'high', 'Delivery '.($short ?? '').' had two sacks fewer than ordered.', 'resolved', $a->subDays(12), [['reply', 'Confirmed from the proof: two sacks melted in transit. The invoice only charges the sacks delivered.']], 'Invoice reflects only the sacks delivered.'],
            ['t7', 'K6', 'price_inquiry', 'low', 'How much is a big sack for a regular order?', 'open', CarbonImmutable::parse($a->toDateString().' 08:10:00', Context::TZ), [], null],
        ];
        foreach ($T as [$key, $k, $topic, $priority, $msg, $status, $at, $replies, $resolution]) {
            if ($c->m->has("ticket:{$key}")) {
                continue;
            }
            $at = $at->hour === 0 ? $at->setTime(9, 15) : $at;
            $prefix = 'TKT-'.$at->format('Ymd').'-';
            $max = DB::table('support_messages')->where('ticket_no', 'like', $prefix.'%')->pluck('ticket_no')->map(fn ($n) => (int) substr($n, -3))->max() ?? 0;
            $toApp = fn (CarbonImmutable $t) => $t->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
            $done = in_array($status, ['resolved', 'closed'], true) ? $at->addHours(5) : null;
            $id = $c->m->insert("ticket:{$key}", 'support_messages', [
                'ticket_no' => $prefix.str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT),
                'customer_id' => $c->cust[$k], 'user_id' => $u($k), 'topic' => $topic, 'priority' => $priority, 'message' => $msg, 'status' => $status,
                'assigned_to' => $status === 'open' ? null : $c->adminUserId,
                'resolved_by' => $done ? $c->adminUserId : null, 'resolved_at' => $done ? $toApp($done) : null, 'resolution_note' => $resolution,
                'closed_at' => $status === 'closed' ? $toApp($done->addDay()) : null,
                'created_at' => $toApp($at), 'updated_at' => $toApp($done ?? $at),
            ]);
            foreach ($replies as $n => [$kind, $body]) {
                $t = $at->addMinutes(40 * ($n + 1));
                $c->m->insert("reply:{$key}:{$n}", 'support_replies', [
                    'support_message_id' => $id, 'user_id' => $kind === 'customer' ? $u($k) : $c->adminUserId,
                    'kind' => $kind, 'body' => $body, 'created_at' => $toApp($t), 'updated_at' => $toApp($t),
                ]);
            }
        }
    }
}
