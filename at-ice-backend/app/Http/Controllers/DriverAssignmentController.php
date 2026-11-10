<?php

namespace App\Http\Controllers;

use App\Models\Delivery;
use App\Models\DeliveryRoute;
use App\Models\DriverStoreAssignment;
use App\Models\LiveEvent;
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
            DriverStoreAssignment::with(['driver:id,name', 'store:id,name,chain,branch_code,customer_type'])
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

    // PUT drivers/{id}/reserve { is_reserve } (owner, 2026-11-11): marks a
    // Driver as a reserve driver or back to a regular one. A reserve has no
    // route; they take an absent Driver's whole route when it fits the truck
    // limits (DeliveryDispatch::reassignRoute).
    public function setReserve(Request $request, $id)
    {
        $data = $request->validate(['is_reserve' => 'required|boolean']);
        $driver = User::where('role', 'driver')->findOrFail($id);
        if ($data['is_reserve'] && ($routeName = DeliveryRoute::where('driver_id', $driver->id)->value('name'))) {
            $why = "{$driver->name} has {$routeName}. Take the route off this driver first; a reserve driver has no route.";

            return response()->json(['message' => $why, 'errors' => ['is_reserve' => [$why]]], 422);
        }
        $driver->forceFill(['is_reserve_driver' => (bool) $data['is_reserve']])->save();
        Audit::log('driver.reserve_set', $driver, null, ['is_reserve' => (bool) $data['is_reserve']]);

        return response()->json(['message' => $driver->name.($data['is_reserve'] ? ' is now a reserve driver.' : ' is a regular driver again.')]);
    }

    // Ends an assignment: one that has not started is removed, a current one
    // ends yesterday. The store's deliveries after today have no driver until
    // Admin assigns one.
    public function destroy($id)
    {
        $a = DriverStoreAssignment::findOrFail($id);
        $freed = self::endOne($a);
        Audit::log('driver_assignment.ended', $a);
        self::tellStores($freed, fn ($first, $n) => "From {$first}, ".($n === 1 ? 'your delivery is' : "your {$n} upcoming deliveries are").' being moved to another driver. The schedule stays the same.');

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
                ->whereNull('route_id') // a route stop keeps the route's Driver
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
