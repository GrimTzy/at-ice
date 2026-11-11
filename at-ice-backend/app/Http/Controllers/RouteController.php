<?php

namespace App\Http\Controllers;

use App\Models\Delivery;
use App\Models\DeliveryRoute;
use App\Models\RouteStop;
use App\Models\User;
use App\Support\Audit;
use App\Support\DeliveryDispatch;
use App\Support\DriverAvailability;
use App\Support\RouteCapacity;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

// Admin > Deliveries > Routes (client, 2026-11-10): routes A to D, each with
// a regular Driver and its stores in delivery order; the trucks' load per
// day; and the Waiting deliveries (an absent Driver's route stops the reserve
// driver could not take), which Admin reschedules. See DeliveryDispatch.
class RouteController extends Controller
{
    // GET /api/routes
    public function index()
    {
        return response()->json(DeliveryRoute::with(['driver:id,name', 'previousDriver:id,name', 'coverDriver:id,name', 'stops.customer:id,name,customer_code,branch_code,address,latitude,longitude,customer_type,is_active'])
            ->orderBy('code')->get()->map(fn ($r) => $this->present($r)));
    }

    // GET /api/routes/{id}
    public function show($id)
    {
        return response()->json($this->present(DeliveryRoute::with(['driver:id,name', 'previousDriver:id,name', 'coverDriver:id,name', 'stops.customer:id,name,customer_code,branch_code,address,latitude,longitude,customer_type,is_active'])->findOrFail($id)));
    }

    // POST /api/routes { name, driver_id?, notes? }: the code is the next
    // free letter (A, B... Z, AA, AB...), made here; it never changes.
    public function store(Request $request)
    {
        $data = $request->validate([
            'code' => 'prohibited',
            'name' => 'required|string|max:100',
            'driver_id' => ['nullable', Rule::exists('users', 'id')->where('role', 'driver')],
            'notes' => 'nullable|string|max:1000',
        ], [
            'code.prohibited' => 'The route code is given by the system.',
            'driver_id.exists' => 'Please select a driver.',
        ]);
        $data['name'] = trim($data['name']);
        if ($why = $this->driverTaken($data['driver_id'] ?? null)) {
            return response()->json(['message' => $why, 'errors' => ['driver_id' => [$why]]], 422);
        }

        // the unique code index catches two routes made at the same moment: try the next letter
        $route = null;
        for ($try = 0; ! $route; $try++) {
            try {
                $route = DeliveryRoute::create($data + ['code' => DeliveryRoute::nextCode(), 'is_active' => true]);
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                if ($try >= 2) {
                    throw $e;
                }
            }
        }
        Audit::log('route.created', $route, null, $data + ['code' => $route->code]);

        return response()->json($this->present($route->load(['driver:id,name', 'previousDriver:id,name', 'coverDriver:id,name', 'stops.customer'])), 201);
    }

    // PUT /api/routes/{id}: name, active, notes, and who drives it (Driver
    // Stores; the code never changes):
    //   driver_id, driver_from?,  the regular Driver ("No end date"), from
    //   driver_to?                driver_from (default today) until driver_to
    //                             (empty: until changed). Before driver_from
    //                             the current regular Driver keeps it; after
    //                             driver_to it has none (reserve or Waiting),
    //                             and Admin is alerted 3 days before. null: none.
    //   cover_driver_id,          a temporary cover ("One day", "One week"):
    //   cover_from?, cover_to     only on those days; then the regular Driver
    //                             is back on his own. null ends the cover.
    // The upcoming deliveries of its stores follow (a day with no route
    // Driver: the store's Driver Stores driver); the stores are told;
    // truck-limit warnings for the Drivers.
    public function update(Request $request, $id)
    {
        $route = DeliveryRoute::findOrFail($id);
        $today = DriverAvailability::today();
        $driverRule = fn () => ['sometimes', 'nullable', Rule::exists('users', 'id')->where('role', 'driver')];
        $data = $request->validate([
            'code' => 'prohibited',
            'name' => 'sometimes|required|string|max:100',
            'driver_id' => $driverRule(),
            'driver_from' => 'sometimes|nullable|date|after_or_equal:'.$today,
            'driver_to' => 'sometimes|nullable|date|after_or_equal:'.$today,
            'cover_driver_id' => $driverRule(),
            'cover_from' => 'sometimes|nullable|date|after_or_equal:'.$today,
            'cover_to' => 'required_with:cover_driver_id|nullable|date|after_or_equal:'.$today,
            'is_active' => 'sometimes|boolean',
            'notes' => 'sometimes|nullable|string|max:1000',
        ], [
            'code.prohibited' => 'The route code is given by the system and cannot be changed.',
            'driver_id.exists' => 'Please select a driver.',
            'cover_driver_id.exists' => 'Please select a driver.',
            'driver_from.after_or_equal' => 'The start date cannot be in the past.',
            'driver_to.after_or_equal' => 'Until must be on or after From.',
            'cover_from.after_or_equal' => 'The start date cannot be in the past.',
            'cover_to.required_with' => 'Pick the last day of the cover.',
            'cover_to.after_or_equal' => 'The end date cannot be in the past.',
        ]);
        if (isset($data['name'])) {
            $data['name'] = trim($data['name']);
        }
        $fields = ['name', 'is_active', 'notes'];
        $update = array_intersect_key($data, array_flip($fields));

        // the regular Driver
        if (array_key_exists('driver_id', $data)) {
            $new = $data['driver_id'] ? (int) $data['driver_id'] : null;
            $from = $new ? Carbon::parse($data['driver_from'] ?? $today)->toDateString() : null;
            $to = $new && ! empty($data['driver_to']) ? Carbon::parse($data['driver_to'])->toDateString() : null;
            if ($to && $to < $from) {
                $why = 'Until must be on or after From.';

                return response()->json(['message' => $why, 'errors' => ['driver_to' => [$why]]], 422);
            }
            if ($why = $this->driverTaken($new, $route->id, $from ?? $today, $to)) {
                return response()->json(['message' => $why, 'errors' => ['driver_id' => [$why]]], 422);
            }
            // who drives it until a later start: the one driving it today (none
            // after their Until), and only up to their Until
            $waiting = $route->driver_from && $route->driver_from->toDateString() > $today;
            $current = $route->regularOn($today);
            $currentTo = $current ? ($waiting ? $route->previous_driver_to : $route->driver_to)?->toDateString() : null;
            $later = $new && $from > $today && $current !== $new;
            $update += [
                'driver_id' => $new,
                'driver_from' => $later ? $from : null,
                'driver_to' => $to,
                'previous_driver_id' => $later ? $current : null,
                'previous_driver_to' => $later ? $currentTo : null,
                // a new Until (or a new driver) is alerted again, 3 days before it
                'driver_to_alerted_at' => $new && $new === (int) $route->driver_id && $to === $route->driver_to?->toDateString() ? $route->driver_to_alerted_at : null,
            ];
        }

        // a temporary cover
        if (array_key_exists('cover_driver_id', $data)) {
            $cover = $data['cover_driver_id'] ? (int) $data['cover_driver_id'] : null;
            $coverFrom = $cover ? Carbon::parse($data['cover_from'] ?? $today)->toDateString() : null;
            $coverTo = $cover ? Carbon::parse($data['cover_to'])->toDateString() : null;
            if ($cover && $coverTo < $coverFrom) {
                $why = 'The end date must be on or after the start date.';

                return response()->json(['message' => $why, 'errors' => ['cover_to' => [$why]]], 422);
            }
            if ($cover && ($why = $this->coverTaken($cover, $coverFrom, $coverTo, $route))) {
                return response()->json(['message' => $why, 'errors' => ['cover_driver_id' => [$why]]], 422);
            }
            $update += ['cover_driver_id' => $cover, 'cover_from' => $coverFrom, 'cover_to' => $coverTo];
        }

        $who = ['driver_id', 'driver_from', 'driver_to', 'previous_driver_id', 'previous_driver_to', 'cover_driver_id', 'cover_from', 'cover_to'];
        $before = $route->only(array_merge($fields, $who));
        $changed = DB::transaction(function () use ($route, $update, $who) {
            $route->update($update);

            return $route->wasChanged(array_merge($who, ['is_active'])) ? DeliveryDispatch::applyRoute($route->fresh()) : [];
        });
        Audit::log('route.updated', $route, $before, $route->only(array_merge($fields, $who)));
        $this->tellStores($changed);

        $route = $route->fresh(['driver:id,name', 'previousDriver:id,name', 'coverDriver:id,name', 'stops.customer']);
        $drivers = array_values(array_filter([$route->driver_id, $route->previous_driver_id, $route->hasCover() ? $route->cover_driver_id : null]));
        $warnings = $drivers ? RouteCapacity::warningsFor($drivers, RouteCapacity::upcomingDates($drivers)) : [];

        return response()->json([
            'message' => 'Route saved.'.$this->whoText($route).($changed ? ' '.count($changed).' upcoming deliveries changed driver.' : ''),
            'route' => $this->present($route),
            'deliveries_changed' => count($changed),
            'warnings' => $warnings,
            'warning' => RouteCapacity::message($warnings),
        ]);
    }

    // DELETE /api/routes/{id}: only a route with no stores (otherwise set it inactive)
    public function destroy($id)
    {
        $route = DeliveryRoute::withCount('stops')->findOrFail($id);
        if ($route->stops_count > 0) {
            return response()->json(['message' => 'Remove the stores from this route first, or set it inactive.'], 422);
        }
        $route->delete();
        Audit::log('route.deleted', $route);

        return response()->json(['message' => "{$route->name} deleted."]);
    }

    // PUT /api/routes/{id}/stops: the whole stop list in delivery order.
    // { stops: [{customer_id, stop_order, stop_group?}] }. A store on another
    // route moves here. Upcoming deliveries follow; stores whose Driver
    // changes are told.
    public function updateStops(Request $request, $id)
    {
        $route = DeliveryRoute::findOrFail($id);
        $data = $request->validate([
            'stops' => 'present|array',
            'stops.*.customer_id' => 'required|integer|distinct|exists:customers,id',
            'stops.*.stop_order' => 'required|integer|min:1|max:999',
            'stops.*.stop_group' => 'nullable|string|max:150',
        ], ['stops.*.customer_id.distinct' => 'A store can only be on the route once.']);

        $newIds = array_map(fn ($s) => (int) $s['customer_id'], $data['stops']);
        $moved = RouteStop::with('route:id,code')->whereIn('customer_id', $newIds)->where('route_id', '!=', $route->id)->get()
            ->map(fn ($s) => ['customer_id' => $s->customer_id, 'from_route' => $s->route?->code])->values()->all();

        $changed = DB::transaction(function () use ($route, $data, $newIds) {
            $oldIds = $route->stops()->pluck('customer_id')->map(fn ($v) => (int) $v)->all();
            RouteStop::whereIn('customer_id', $newIds)->orWhere('route_id', $route->id)->delete();
            foreach ($data['stops'] as $s) {
                RouteStop::create([
                    'route_id' => $route->id,
                    'customer_id' => $s['customer_id'],
                    'stop_order' => $s['stop_order'],
                    'stop_group' => $s['stop_group'] ?? null,
                ]);
            }

            return DeliveryDispatch::applyRoute($route->fresh(), array_values(array_diff($oldIds, $newIds)));
        });
        Audit::log('route.stops_saved', $route, null, ['stops' => count($newIds), 'moved_from_other_routes' => count($moved)]);
        $this->tellStores($changed);

        $warnings = $route->driver_id ? RouteCapacity::warningsFor([$route->driver_id], RouteCapacity::upcomingDates([$route->driver_id])) : [];

        return response()->json([
            'message' => 'Stops saved.',
            'route' => $this->present($route->fresh(['driver:id,name', 'previousDriver:id,name', 'coverDriver:id,name', 'stops.customer'])),
            'moved_from_other_routes' => $moved,
            'deliveries_changed' => count($changed),
            'warnings' => $warnings,
            'warning' => RouteCapacity::message($warnings),
        ]);
    }

    // GET /api/routes/capacity?date=YYYY-MM-DD: each Driver's (and the
    // reserve's) load that day against the truck limits.
    public function capacity(Request $request)
    {
        $data = $request->validate(['date' => 'nullable|date']);
        $date = Carbon::parse($data['date'] ?? DriverAvailability::today())->toDateString();
        $drivers = User::where('role', 'driver')->orderBy('name')->get(['id', 'name', 'is_reserve_driver']);
        $loads = RouteCapacity::loads($drivers->pluck('id')->all(), [$date]);
        $routes = DeliveryRoute::codesOn($date);

        return response()->json([
            'date' => $date,
            'limits' => RouteCapacity::limits(),
            'drivers' => $drivers->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'is_reserve' => (bool) $u->is_reserve_driver,
                'route_code' => $routes[$u->id] ?? null,
            ] + RouteCapacity::summary($loads[$u->id][$date] ?? null))->values(),
        ]);
    }

    // GET /api/deliveries/waiting: deliveries with no Driver (status queued),
    // oldest day first, each with the day Reschedule would move it to.
    public function waiting()
    {
        $rows = Delivery::with(['customer:id,name,branch_code,address', 'items:id,delivery_id,product_type,quantity_ordered', 'route:id,code,name', 'reassignedFrom:id,name', 'contract:id,contract_no,name'])
            ->where('status', 'queued')
            ->orderBy('scheduled_date')->orderBy('ordered_at')->orderBy('id')
            ->get();

        return response()->json($rows->map(fn ($d) => $d->toArray() + [
            'is_past' => $d->scheduledDateString() < DriverAvailability::today(),
            'next_delivery_day' => DeliveryDispatch::nextDeliveryDay($d),
        ])->values());
    }

    // POST /api/deliveries/{id}/reschedule { date? }: one Waiting delivery
    public function reschedule(Request $request, $id)
    {
        $data = $this->validateDate($request);
        $delivery = Delivery::findOrFail($id);
        if ($why = $this->notWaiting($delivery)) {
            return response()->json(['message' => $why], 422);
        }

        $warnings = DeliveryDispatch::reschedule($delivery, $data['date'] ?? null);
        $delivery->refresh()->load(['driver:id,name', 'customer:id,name', 'route:id,code']);
        $day = Carbon::parse($delivery->scheduledDateString())->format('M j');

        return response()->json([
            'message' => "{$delivery->delivery_no} moved to {$day}".($delivery->driver ? " with {$delivery->driver->name}." : '. It has no driver for that day yet.'),
            'delivery' => $delivery,
            'warnings' => $warnings,
            'warning' => RouteCapacity::message($warnings),
        ]);
    }

    // POST /api/deliveries/reschedule { ids: [...], date? }: several at once
    // (e.g. a whole Waiting route). Each goes to its own next delivery day
    // unless a date is given.
    public function rescheduleMany(Request $request)
    {
        $data = $this->validateDate($request, [
            'ids' => 'required|array|min:1|max:500',
            'ids.*' => 'integer|distinct',
        ]);
        $deliveries = Delivery::whereIn('id', $data['ids'])->get();

        $moved = 0;
        $skipped = [];
        $warnings = [];
        DB::transaction(function () use ($deliveries, $data, &$moved, &$skipped, &$warnings) {
            foreach ($deliveries as $d) {
                if ($this->notWaiting($d)) {
                    $skipped[] = $d->delivery_no;

                    continue;
                }
                $warnings = array_merge($warnings, DeliveryDispatch::reschedule($d, $data['date'] ?? null));
                $moved++;
            }
        });
        // one warning per Driver, day and limit
        $warnings = array_values(collect($warnings)->unique(fn ($w) => "{$w['driver_id']}|{$w['date']}|{$w['kind']}")->all());

        return response()->json([
            'message' => "{$moved} ".($moved === 1 ? 'delivery' : 'deliveries').' rescheduled.'.($skipped ? ' Not waiting, left as is: '.implode(', ', $skipped).'.' : ''),
            'rescheduled' => $moved,
            'skipped' => $skipped,
            'warnings' => $warnings,
            'warning' => RouteCapacity::message($warnings),
        ]);
    }

    private function validateDate(Request $request, array $rules = []): array
    {
        return $request->validate($rules + [
            'date' => 'nullable|date|after:'.DriverAvailability::today().'|before_or_equal:'.Carbon::parse(DriverAvailability::today())->addDays(60)->toDateString(),
        ], [
            'date.after' => 'Pick a day after today.',
            'date.before_or_equal' => 'Pick a day within the next 60 days.',
        ]);
    }

    private function notWaiting(Delivery $d): ?string
    {
        return $d->status === 'queued' || ($d->status === 'pending' && ! $d->driver_id)
            ? null
            : "{$d->delivery_no} is not waiting for a driver, so it cannot be rescheduled here.";
    }

    // A Driver is the regular Driver of one route at a time, and a reserve
    // driver has no route. Another route only counts on days that overlap
    // $from..$to ($to null: no end), so a Driver whose Until on Route A has
    // passed, or comes before $from, may take Route B.
    private function driverTaken(?int $driverId, ?int $exceptRouteId = null, ?string $from = null, ?string $to = null): ?string
    {
        if (! $driverId) {
            return null;
        }
        if (User::whereKey($driverId)->value('is_reserve_driver')) {
            return 'This driver is a reserve driver. Make them a regular driver first (Driver Stores).';
        }
        $from ??= DriverAvailability::today();
        $other = DeliveryRoute::where('driver_id', $driverId)->where('is_active', true)
            ->when($exceptRouteId, fn ($q) => $q->where('id', '!=', $exceptRouteId))
            ->where(fn ($q) => $q->whereNull('driver_to')->orWhereDate('driver_to', '>=', $from))
            ->when($to, fn ($q) => $q->where(fn ($w) => $w->whereNull('driver_from')->orWhereDate('driver_from', '<=', $to)))
            ->value('name');

        return $other ? "This driver already has {$other}." : null;
    }

    // A cover Driver drives no other route on those days: not the regular
    // Driver of another active route, nor covering one at the same time.
    // A reserve driver may cover.
    private function coverTaken(int $driverId, string $from, string $to, DeliveryRoute $route): ?string
    {
        if ((int) $route->driver_id === $driverId && ! $route->driver_from) {
            return 'This driver is already the regular driver of this route.';
        }
        $regular = DeliveryRoute::where('id', '!=', $route->id)->where('is_active', true)->where('driver_id', $driverId)->value('name');
        if ($regular) {
            return "This driver already has {$regular}.";
        }
        $covering = DeliveryRoute::where('id', '!=', $route->id)->where('cover_driver_id', $driverId)
            ->whereDate('cover_from', '<=', $to)->whereDate('cover_to', '>=', $from)->value('name');

        return $covering ? "This driver already covers {$covering} on some of those days." : null;
    }

    private function present(DeliveryRoute $r): array
    {
        $stops = $r->relationLoaded('stops') ? $r->stops : collect();

        return [
            'id' => $r->id,
            'code' => $r->code,
            'name' => $r->name,
            'driver_id' => $r->driver_id,
            'driver' => $r->driver ? ['id' => $r->driver->id, 'name' => $r->driver->name] : null,
            // a new regular Driver from a later day; previous_driver drives it until then
            'driver_from' => $r->driver_from?->toDateString(),
            // the regular Driver's last day (null: until changed); after it the route has none
            'driver_to' => $r->driver_to?->toDateString(),
            'previous_driver' => $r->driver_from && $r->previousDriver ? ['id' => $r->previousDriver->id, 'name' => $r->previousDriver->name] : null,
            'previous_driver_to' => $r->driver_from ? $r->previous_driver_to?->toDateString() : null,
            // a temporary cover that is running or still to come (null otherwise)
            'cover' => $r->hasCover() && $r->coverDriver ? [
                'driver' => ['id' => $r->coverDriver->id, 'name' => $r->coverDriver->name],
                'from' => $r->cover_from?->toDateString(),
                'to' => $r->cover_to?->toDateString(),
            ] : null,
            'driver_today_id' => $r->driverOn(DriverAvailability::today()),
            'is_active' => $r->is_active,
            'notes' => $r->notes,
            'stop_count' => $stops->pluck('stop_order')->unique()->count(),
            'customer_count' => $stops->count(),
            'stops' => $stops->map(fn ($s) => [
                'id' => $s->id,
                'customer_id' => $s->customer_id,
                'stop_order' => $s->stop_order,
                'stop_group' => $s->stop_group,
                'customer' => $s->customer?->only(['id', 'name', 'customer_code', 'branch_code', 'address', 'latitude', 'longitude', 'customer_type', 'is_active']),
            ])->values(),
        ];
    }

    // " Ben covers it Nov 11 to Nov 17, then Ana is back." for the save message.
    private function whoText(DeliveryRoute $r): string
    {
        $regular = $r->driver?->name;
        $until = $r->driver_to ? " until {$r->driver_to->format('M j')}" : '';
        $text = ! $regular
            ? ' It has no regular driver; on days without one its stores go to their Driver Stores driver.'
            : ($r->driver_from
                ? " {$regular} is the regular driver from {$r->driver_from->format('M j')}{$until}".($r->previousDriver ? " ({$r->previousDriver->name} until then)." : '.')
                : " {$regular} is the regular driver{$until}.");
        if ($regular && $r->driver_to) {
            $text .= ' After that the route has no regular driver; you will be alerted 3 days before.';
        }
        if ($r->hasCover() && $r->coverDriver) {
            $text .= " {$r->coverDriver->name} covers it from {$r->cover_from->format('M j')} to {$r->cover_to->format('M j')}"
                .($regular ? ", then {$regular} is back." : '.');
        }

        return $text;
    }

    // One portal notice per store (on its first changed delivery).
    private function tellStores(array $ids): void
    {
        if (! $ids) {
            return;
        }
        Delivery::with(['customer', 'driver:id,name'])->whereIn('id', $ids)->orderBy('scheduled_date')->get()
            ->groupBy('customer_id')
            ->each(function ($list) {
                $first = $list->first();
                $n = $list->count();
                $who = $first->driver ? "driver {$first->driver->name}" : 'another driver';
                DeliveryDispatch::notifyChange($first, 'driver_changed', 'From '.DeliveryDispatch::day($first).', '.($n === 1 ? 'your delivery' : "your {$n} upcoming deliveries")." will be handled by {$who}.");
            });
    }
}
