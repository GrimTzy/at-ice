<?php

namespace App\Support;

use App\Models\Delivery;
use App\Models\DeliveryContract;
use App\Models\RouteStop;
use App\Models\User;
use Carbon\Carbon;

// Truck load and stores per Driver per day (client, 2026-11-10). A truck makes
// one trip a day loaded full (150 big sacks, 350 small sacks, 500 bags) and
// goes to at most 80 stores; each mall tenant counts as a store. Admin edits
// the limits in Settings > Deliveries.
//
// Going over only warns (contract approval, route changes, a delivery made or
// edited by Admin). The one place it decides is the reserve driver: an absent
// Driver's route goes to the reserve only if it fits (DeliveryDispatch).
// Under the limit is fine: the crew sells what is left off-route.
class RouteCapacity
{
    // product_type => setting key
    public const PRODUCTS = [
        'big_sack' => 'delivery.truck_big_sacks',
        'small_sack' => 'delivery.truck_small_sacks',
        'bags' => 'delivery.truck_bags',
    ];

    public const LABELS = ['big_sack' => 'big sacks', 'small_sack' => 'small sacks', 'bags' => 'bags', 'stores' => 'stores'];

    // ['big_sack' => 150, 'small_sack' => 350, 'bags' => 500, 'stores' => 80]
    public static function limits(): array
    {
        $out = [];
        foreach (self::PRODUCTS as $type => $key) {
            $out[$type] = (int) Settings::get($key);
        }
        $out['stores'] = (int) Settings::get('delivery.max_stores_per_driver_day');

        return $out;
    }

    private static function emptyLoad(): array
    {
        return ['big_sack' => 0, 'small_sack' => 0, 'bags' => 0, 'customers' => []];
    }

    // What the Drivers already carry: [driver_id][Y-m-d] => load, from their
    // deliveries that are not cancelled. $exclude: delivery ids left out (the
    // ones being moved).
    public static function loads(array $driverIds, array $dates, array $exclude = []): array
    {
        $driverIds = array_values(array_unique(array_filter($driverIds)));
        $dates = array_values(array_unique($dates));
        if (! $driverIds || ! $dates) {
            return [];
        }
        sort($dates);
        $wanted = array_flip($dates);

        $rows = Delivery::with('items:id,delivery_id,product_type,quantity_ordered')
            ->whereIn('driver_id', $driverIds)
            ->whereBetween('scheduled_date', [reset($dates), end($dates).' 23:59:59'])
            ->where('status', '!=', 'cancelled')
            ->where('delivery_type', 'outbound')
            ->when($exclude, fn ($q) => $q->whereNotIn('id', $exclude))
            ->get(['id', 'driver_id', 'customer_id', 'scheduled_date']);

        $out = [];
        foreach ($rows as $d) {
            $date = $d->scheduledDateString();
            if (! isset($wanted[$date])) {
                continue;
            }
            $out[$d->driver_id][$date] ??= self::emptyLoad();
            self::add($out[$d->driver_id][$date], $d->items->map(fn ($i) => ['product_type' => $i->product_type, 'quantity' => (int) $i->quantity_ordered])->all(), $d->customer_id);
        }

        return $out;
    }

    // Adds one delivery (its items and store) to a load.
    public static function add(array &$load, array $items, ?int $customerId): void
    {
        foreach ($items as $item) {
            $type = $item['product_type'];
            if (array_key_exists($type, self::PRODUCTS)) {
                $load[$type] += (int) ($item['quantity'] ?? $item['quantity_ordered'] ?? 0);
            }
        }
        if ($customerId) {
            $load['customers'][$customerId] = true;
        }
    }

    // One Driver's day as shown to Admin: what is loaded and the limits.
    public static function summary(?array $load): array
    {
        $load ??= self::emptyLoad();
        $limits = self::limits();

        return [
            'big_sack' => $load['big_sack'],
            'small_sack' => $load['small_sack'],
            'bags' => $load['bags'],
            'stores' => count($load['customers']),
            'limits' => $limits,
            'over' => self::overList($load, $limits),
        ];
    }

    private static function overList(array $load, array $limits): array
    {
        $over = [];
        foreach (array_keys(self::PRODUCTS) as $type) {
            if ($load[$type] > $limits[$type]) {
                $over[] = $type;
            }
        }
        if (count($load['customers']) > $limits['stores']) {
            $over[] = 'stores';
        }

        return $over;
    }

    // Does $extra fit on the Driver's truck on $date, with what they already
    // carry? Used before giving the reserve driver a route.
    public static function fits(int $driverId, string $date, array $extra): bool
    {
        $load = self::loads([$driverId], [$date])[$driverId][$date] ?? self::emptyLoad();
        foreach ($extra as $row) {
            self::add($load, $row['items'], $row['customer_id']);
        }

        return self::overList($load, self::limits()) === [];
    }

    // Warnings for the given loads: one per Driver, day and limit gone over.
    // [{driver_id, driver, date, kind, planned, limit, message}]
    public static function warnings(array $loads): array
    {
        $limits = self::limits();
        $names = User::whereIn('id', array_keys($loads))->pluck('name', 'id');
        $out = [];
        ksort($loads);
        foreach ($loads as $driverId => $days) {
            ksort($days);
            foreach ($days as $date => $load) {
                foreach (self::overList($load, $limits) as $kind) {
                    $planned = $kind === 'stores' ? count($load['customers']) : $load[$kind];
                    $name = $names[$driverId] ?? 'Driver';
                    $out[] = [
                        'driver_id' => (int) $driverId,
                        'driver' => $name,
                        'date' => $date,
                        'kind' => $kind,
                        'planned' => $planned,
                        'limit' => $limits[$kind],
                        'message' => "{$name} on ".Carbon::parse($date)->format('M j').": {$planned} ".self::LABELS[$kind]." (limit {$limits[$kind]}).",
                    ];
                }
            }
        }

        return $out;
    }

    // Warnings for what these Drivers already have on these days.
    public static function warningsFor(array $driverIds, array $dates): array
    {
        return self::warnings(self::loads($driverIds, $dates));
    }

    // Upcoming days (today on, up to $days ahead) that a Driver has deliveries.
    public static function upcomingDates(array $driverIds, int $days = 60): array
    {
        $today = DriverAvailability::today();

        return Delivery::whereIn('driver_id', array_filter($driverIds))
            ->whereIn('status', ['pending', 'queued'])
            ->whereBetween('scheduled_date', [$today, Carbon::parse($today)->addDays($days)->toDateString().' 23:59:59'])
            ->get(['id', 'scheduled_date'])
            ->map(fn ($d) => $d->scheduledDateString())
            ->unique()->values()->all();
    }

    // What an approved contract would add to each Driver's days, with what
    // they already carry: warnings, and how many store-days have no Driver.
    // Stores that already have a delivery that day are skipped, as generate()
    // does.
    public static function forContract(DeliveryContract $contract): array
    {
        $dates = DeliveryDispatch::dates($contract);
        $storeIds = $contract->stores()->pluck('customers.id')->all();
        if (! $dates || ! $storeIds) {
            return ['warnings' => [], 'without_driver' => 0];
        }

        $booked = Delivery::whereIn('customer_id', $storeIds)
            ->whereBetween('scheduled_date', [reset($dates), end($dates).' 23:59:59'])
            ->where('status', '!=', 'cancelled')
            ->where('is_top_up', false)
            ->get(['customer_id', 'scheduled_date'])
            ->map(fn ($d) => $d->customer_id.'|'.$d->scheduledDateString())
            ->flip();

        $routes = RouteStop::forCustomers($storeIds);
        $items = array_map(fn ($i) => ['product_type' => $i['product_type'], 'quantity' => (int) $i['quantity']], $contract->items ?? []);
        $plan = [];
        $withoutDriver = 0;
        foreach ($dates as $date) {
            foreach ($storeIds as $storeId) {
                if (isset($booked[$storeId.'|'.$date])) {
                    continue;
                }
                $driverId = DeliveryDispatch::driverFor($storeId, $date, $routes);
                if (! $driverId) {
                    $withoutDriver++;

                    continue;
                }
                $plan[$driverId][$date][] = ['items' => $items, 'customer_id' => $storeId];
            }
        }

        $loads = self::loads(array_keys($plan), $dates);
        foreach ($plan as $driverId => $days) {
            foreach ($days as $date => $rows) {
                $loads[$driverId][$date] ??= self::emptyLoad();
                foreach ($rows as $row) {
                    self::add($loads[$driverId][$date], $row['items'], $row['customer_id']);
                }
            }
        }
        // only the Drivers and days this contract touches
        $loads = array_intersect_key($loads, $plan);
        foreach ($loads as $driverId => $days) {
            $loads[$driverId] = array_intersect_key($days, $plan[$driverId]);
        }

        return ['warnings' => self::warnings($loads), 'without_driver' => $withoutDriver];
    }

    // One line for a toast: how many Driver-days are over, with the first ones.
    public static function message(array $warnings): ?string
    {
        if (! $warnings) {
            return null;
        }
        $first = array_slice(array_column($warnings, 'message'), 0, 3);
        $more = count($warnings) - count($first);

        return 'Over the truck limits: '.implode(' ', $first).($more > 0 ? " And {$more} more." : '');
    }
}
