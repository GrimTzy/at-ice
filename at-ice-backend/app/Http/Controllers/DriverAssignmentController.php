<?php

namespace App\Http\Controllers;

use App\Models\Delivery;
use App\Models\DriverStoreAssignment;
use App\Models\LiveEvent;
use App\Models\StoreRoute;
use App\Models\User;
use App\Support\Audit;
use App\Support\DeliveryDispatch;
use App\Support\DriverAvailability;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

// Admin picks which Driver handles which stores (semi-manual assignment), and
// records a Driver who is absent, on emergency leave or on a rest day; the
// system then reassigns that Driver's deliveries (App\Support\DeliveryDispatch).
class DriverAssignmentController extends Controller
{
    // Assignments that are current or start later.
    public function index()
    {
        return response()->json(
            DriverStoreAssignment::with(['driver:id,name', 'store:id,name,chain,branch_code,customer_type,store_route_id'])
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', DriverAvailability::today()))
                ->orderBy('effective_from')
                ->get()
        );
    }

    // Driver X handles these stores from a date (usually the next week or pay
    // period). Each store's earlier assignment ends the day before; one that
    // would only start later is replaced.
    public function store(Request $request)
    {
        $data = $request->validate([
            'driver_id' => ['required', Rule::exists('users', 'id')->where('role', 'driver')],
            'store_ids' => 'required|array|min:1',
            'store_ids.*' => 'integer|distinct|exists:customers,id',
            'effective_from' => 'required|date|after_or_equal:'.DriverAvailability::today(),
            'effective_to' => 'nullable|date|after_or_equal:effective_from',
        ], ['driver_id.exists' => 'Please select a driver.', 'store_ids.required' => 'Pick at least one store.']);

        $moved = 0;
        $changedIds = [];
        $from = Carbon::parse($data['effective_from'])->toDateString();
        $made = self::assignStores((int) $data['driver_id'], $data['store_ids'], $from, $data['effective_to'] ?? null, $request->user()->id, $moved, $changedIds);

        $driver = User::find($data['driver_id']);
        $count = count($made);
        LiveEvent::create([
            'module' => 'deliveries',
            'action' => 'updated',
            'message' => "{$driver->name} now handles {$count} ".($count === 1 ? 'store' : 'stores').' from '.Carbon::parse($from)->format('M j'),
            'roles' => ['employee:salesman', 'employee:checker'],
            'user_ids' => [$driver->id],
            'actor_id' => $request->user()->id,
        ]);
        Audit::log('driver_assignment.saved', $driver, null, $data + ['deliveries_updated' => $moved]);
        self::tellStores($changedIds, fn ($first, $n) => "From {$first}, ".($n === 1 ? 'your delivery' : "your {$n} upcoming deliveries")." will be handled by driver {$driver->name}.");

        return response()->json([
            'message' => "{$driver->name} was assigned {$count} ".($count === 1 ? 'store' : 'stores').". {$moved} upcoming deliveries now go to them.",
            'assignments' => $made,
        ], 201);
    }

    // Hands a whole route to a Driver (Driver Stores tab): every store on the
    // route follows the Driver from the start date, on the days of its contract.
    // Stores added to the route later follow too (StoreRouteController).
    public function assignRoute(Request $request)
    {
        $data = $request->validate([
            'route_id' => 'required|integer|exists:store_routes,id',
            'driver_id' => ['required', Rule::exists('users', 'id')->where('role', 'driver')->where('is_active', 1)->where('is_reserve_driver', 0)],
            'effective_from' => 'required|date|after_or_equal:'.DriverAvailability::today(),
            'effective_to' => 'nullable|date|after_or_equal:effective_from',
        ], ['driver_id.exists' => 'Pick an active driver who is not a reserve driver.', 'route_id.required' => 'Please select a route.', 'route_id.exists' => 'This route no longer exists.']);

        $route = StoreRoute::with('stores:customers.id,customers.store_route_id')->findOrFail($data['route_id']);
        $from = Carbon::parse($data['effective_from'])->toDateString();
        $moved = 0;
        $changedIds = [];
        $stores = $route->stores->pluck('id')->all();

        DB::transaction(function () use ($route, $data, $from, $stores, $request, &$moved, &$changedIds) {
            // the route's old driver stops handling it from the new start date
            if ($route->driver_id && (int) $route->driver_id !== (int) $data['driver_id']) {
                self::endRouteDriver($route, $stores);
            }
            $route->update(['driver_id' => $data['driver_id'], 'effective_from' => $from, 'effective_to' => $data['effective_to'] ?? null]);
            if ($stores) {
                self::assignStores((int) $data['driver_id'], $stores, $from, $data['effective_to'] ?? null, $request->user()->id, $moved, $changedIds);
            }
        });

        $driver = User::find($data['driver_id']);
        LiveEvent::create([
            'module' => 'deliveries',
            'action' => 'updated',
            'message' => "{$driver->name} now handles {$route->name} from ".Carbon::parse($from)->format('M j'),
            'roles' => ['employee:salesman', 'employee:checker'],
            'user_ids' => [$driver->id],
            'actor_id' => $request->user()->id,
        ]);
        Audit::log('driver_assignment.route_saved', $driver, null, $data + ['stores' => count($stores), 'deliveries_updated' => $moved]);
        self::tellStores($changedIds, fn ($first, $n) => "From {$first}, ".($n === 1 ? 'your delivery' : "your {$n} upcoming deliveries")." will be handled by driver {$driver->name}.");

        return response()->json([
            'message' => "{$route->name} was given to {$driver->name}. {$moved} upcoming deliveries now go to them.".(count($stores) ? '' : ' The route has no stores yet; stores you add will follow.'),
        ], 201);
    }

    // Marks a Driver as a reserve (no route; covers a driver who is off, before
    // the regular drivers) or back to a regular driver.
    public function setReserve(Request $request, $id)
    {
        $data = $request->validate(['is_reserve' => 'required|boolean']);
        $driver = User::where('role', 'driver')->findOrFail($id);
        if ($data['is_reserve'] && StoreRoute::where('driver_id', $driver->id)->exists()) {
            return response()->json(['message' => "{$driver->name} has a route. Take the route off first; a reserve driver has no route.", 'errors' => ['is_reserve' => ['Take the route off this driver first.']]], 422);
        }
        $driver->forceFill(['is_reserve_driver' => (bool) $data['is_reserve']])->save();
        Audit::log('driver.reserve_set', $driver, null, ['is_reserve' => (bool) $data['is_reserve']]);

        return response()->json(['message' => $driver->name.($data['is_reserve'] ? ' is now a reserve driver.' : ' is a regular driver again.')]);
    }

    // Takes the route off its Driver: the stores' current assignments to that Driver end.
    public function unassignRoute($id)
    {
        $route = StoreRoute::with('stores:customers.id,customers.store_route_id')->findOrFail($id);
        $freed = [];
        DB::transaction(function () use ($route, &$freed) {
            $freed = self::endRouteDriver($route, $route->stores->pluck('id')->all());
            $route->update(['driver_id' => null, 'effective_from' => null, 'effective_to' => null]);
        });
        Audit::log('driver_assignment.route_ended', $route);
        self::tellStores($freed, fn ($first, $n) => "From {$first}, ".($n === 1 ? 'your delivery is' : "your {$n} upcoming deliveries are")." being moved to another driver. The schedule stays the same.");

        return response()->json(['message' => "{$route->name} was taken off its driver."]);
    }

    // Ends the route Driver's current or future assignment for these stores.
    // Returns the freed delivery ids.
    public static function endRouteDriver(StoreRoute $route, array $storeIds): array
    {
        if (! $route->driver_id || ! $storeIds) {
            return [];
        }
        $freed = [];
        DriverStoreAssignment::where('driver_id', $route->driver_id)->whereIn('store_id', $storeIds)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', DriverAvailability::today()))
            ->get()
            ->each(function ($a) use (&$freed) {
                $freed = array_merge($freed, self::endOne($a));
            });

        return $freed;
    }
    // Ends an assignment: one that has not started is removed, a current one
    // ends yesterday. The store's deliveries after today have no driver until
    // Admin assigns one.
    public function destroy($id)
    {
        $a = DriverStoreAssignment::findOrFail($id);
        $freed = self::endOne($a);
        Audit::log('driver_assignment.ended', $a);
        self::tellStores($freed, fn ($first, $n) => "From {$first}, ".($n === 1 ? 'your delivery is' : "your {$n} upcoming deliveries are")." being moved to another driver. The schedule stays the same.");

        return response()->json(['message' => 'Assignment ended.']);
    }

    // Driver X handles these stores from $from. Each store's earlier assignment
    // ends the day before; one that would only start later is replaced.
    // Returns the new assignments; $moved counts upcoming deliveries re-pointed,
    // $changedIds lists them.
    public static function assignStores(int $driverId, array $storeIds, string $from, ?string $to, int $byUserId, int &$moved, array &$changedIds): array
    {
        $dayBefore = Carbon::parse($from)->subDay()->toDateString();

        return DB::transaction(function () use ($driverId, $storeIds, $from, $to, $byUserId, $dayBefore, &$moved, &$changedIds) {
            $made = [];
            foreach ($storeIds as $storeId) {
                $open = DriverStoreAssignment::where('store_id', $storeId)
                    ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $from))
                    ->lockForUpdate()
                    ->get();
                foreach ($open as $old) {
                    $old->effective_from->toDateString() >= $from
                        ? $old->delete()
                        : $old->update(['effective_to' => $dayBefore]);
                }

                $a = DriverStoreAssignment::create([
                    'driver_id' => $driverId,
                    'store_id' => $storeId,
                    'effective_from' => $from,
                    'effective_to' => $to,
                    'created_by' => $byUserId,
                ]);
                $moved += DeliveryDispatch::applyAssignment($a, $changed);
                $changedIds = array_merge($changedIds, $changed);
                $made[] = $a;
            }

            return $made;
        });
    }

    // Ends one assignment: one that has not started is removed, a current one
    // ends yesterday. The store's pending deliveries after today have no driver
    // until Admin assigns one. Returns the freed delivery ids.
    public static function endOne(DriverStoreAssignment $a): array
    {
        $today = DriverAvailability::today();
        $yesterday = Carbon::parse($today)->subDay()->toDateString();

        $freed = [];
        DB::transaction(function () use ($a, $today, $yesterday, &$freed) {
            $q = Delivery::where('customer_id', $a->store_id)
                ->where('driver_id', $a->driver_id)
                ->where('status', 'pending')
                ->whereNull('reassigned_from_driver_id')
                ->whereDate('scheduled_date', '>', $today);
            $freed = (clone $q)->pluck('id')->all();
            $q->update(['driver_id' => null, 'updated_at' => now()]);

            $a->effective_from->toDateString() > $yesterday
                ? $a->delete()
                : $a->update(['effective_to' => $yesterday]);
        });

        return $freed;
    }

    // One notice per store (on its first changed delivery), not one per delivery.
    public static function tellStores(array $ids, callable $message): void
    {
        if (! $ids) {
            return;
        }
        Delivery::with('customer')->whereIn('id', $ids)->orderBy('scheduled_date')->get()
            ->groupBy('customer_id')
            ->each(function ($list) use ($message) {
                $first = $list->first();
                DeliveryDispatch::notifyChange($first, 'driver_changed', $message(DeliveryDispatch::day($first), $list->count()));
            });
    }
}
