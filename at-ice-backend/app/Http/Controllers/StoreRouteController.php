<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\StoreRoute;
use App\Support\Audit;
use App\Support\DriverAvailability;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

// Admin > Deliveries > Routes: named groups of stores. A store is on one route
// at most, so putting it on a route takes it off its old one. When a route is
// handed to a Driver (Driver Stores tab), the stores that join it follow that
// Driver and the ones that leave stop following (owner, 2026-11-11).
class StoreRouteController extends Controller
{
    public function index()
    {
        return response()->json(
            StoreRoute::with(['driver:id,name', 'stores' => fn ($q) => $q->where('is_active', true)->orderBy('name')])
                ->orderBy('name')->get()->map(fn ($r) => $this->shape($r))
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', $this->uniqueName()],
            'store_ids' => ['sometimes', 'array'],
            'store_ids.*' => ['integer', 'distinct', Rule::exists('customers', 'id')->where('is_active', true)],
        ], $this->messages());

        $route = DB::transaction(function () use ($data, $request) {
            $route = StoreRoute::create(['name' => trim($data['name'])]);
            $this->assign($route, $data['store_ids'] ?? [], $request->user()->id);

            return $route;
        });
        Audit::log('store_route.created', $route, null, ['name' => $route->name]);

        return response()->json($this->shape($route->fresh(['driver', 'stores'])), 201);
    }

    public function update(Request $request, $id)
    {
        $route = StoreRoute::findOrFail($id);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:60', $this->uniqueName($route->id)],
            'store_ids' => ['sometimes', 'array'],
            'store_ids.*' => ['integer', 'distinct', Rule::exists('customers', 'id')->where('is_active', true)],
        ], $this->messages());

        $before = ['name' => $route->name, 'stores' => $route->stores()->pluck('id')->all()];
        DB::transaction(function () use ($route, $data, $request) {
            if (isset($data['name'])) {
                $route->update(['name' => trim($data['name'])]);
            }
            if (array_key_exists('store_ids', $data)) {
                $this->assign($route, $data['store_ids'], $request->user()->id);
            }
        });
        Audit::log('store_route.updated', $route, $before, ['name' => $route->name, 'stores' => $route->stores()->pluck('id')->all()]);

        return response()->json($this->shape($route->fresh(['driver', 'stores'])));
    }

    public function destroy($id)
    {
        $route = StoreRoute::with('stores:customers.id,customers.store_route_id')->findOrFail($id);
        Audit::log('store_route.deleted', $route, ['name' => $route->name, 'stores' => $route->stores->count()], null);
        $freed = DB::transaction(function () use ($route) {
            $freed = DriverAssignmentController::endRouteDriver($route, $route->stores->pluck('id')->all());
            $route->delete(); // its stores simply have no route again

            return $freed;
        });
        DriverAssignmentController::tellStores($freed, fn ($first, $n) => "From {$first}, ".($n === 1 ? 'your delivery is' : "your {$n} upcoming deliveries are")." being moved to another driver. The schedule stays the same.");

        return response()->json(['message' => "{$route->name} was removed. Its stores are not on a route now."]);
    }

    // The route holds exactly these stores; any store on another route moves
    // here. A route with a Driver gives its new stores to that Driver and
    // frees the ones that left; a store that moved from another route with a
    // Driver stops following that Driver unless this route has its own.
    private function assign(StoreRoute $route, array $storeIds, int $byUserId): void
    {
        $oldIds = Customer::where('store_route_id', $route->id)->pluck('id')->all();
        $removed = array_values(array_diff($oldIds, $storeIds));
        $added = array_values(array_diff($storeIds, $oldIds));

        // stores that come from another route with a Driver
        $fromOther = Customer::whereIn('id', $added)->whereNotNull('store_route_id')->where('store_route_id', '!=', $route->id)->get(['id', 'store_route_id']);

        Customer::where('store_route_id', $route->id)->whereNotIn('id', $storeIds)->update(['store_route_id' => null]);
        if ($storeIds) {
            Customer::whereIn('id', $storeIds)->update(['store_route_id' => $route->id]);
        }

        $freed = DriverAssignmentController::endRouteDriver($route, $removed);
        if (! $route->driver_id) {
            foreach ($fromOther->groupBy('store_route_id') as $routeId => $stores) {
                $other = StoreRoute::find($routeId);
                $freed = array_merge($freed, $other ? DriverAssignmentController::endRouteDriver($other, $stores->pluck('id')->all()) : []);
            }
        } elseif ($added) {
            $from = max(DriverAvailability::today(), optional($route->effective_from)->toDateString() ?? '');
            $moved = 0;
            $changed = [];
            DriverAssignmentController::assignStores((int) $route->driver_id, $added, $from, optional($route->effective_to)->toDateString(), $byUserId, $moved, $changed);
            DriverAssignmentController::tellStores($changed, fn ($first, $n) => "From {$first}, ".($n === 1 ? 'your delivery' : "your {$n} upcoming deliveries")." will be handled by driver {$route->driver?->name}.");
        }
        DriverAssignmentController::tellStores($freed, fn ($first, $n) => "From {$first}, ".($n === 1 ? 'your delivery is' : "your {$n} upcoming deliveries are")." being moved to another driver. The schedule stays the same.");
    }

    // A route name is not used twice, whatever the capital letters or extra spaces.
    private function uniqueName(?int $ignoreId = null): \Closure
    {
        return function ($attribute, $value, $fail) use ($ignoreId) {
            $taken = StoreRoute::whereRaw('lower(trim(name)) = ?', [mb_strtolower(trim((string) $value))])
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->exists();
            if ($taken) {
                $fail('A route with this name already exists.');
            }
            if (trim((string) $value) === '') {
                $fail('Enter a name for the route.');
            }
        };
    }

    private function messages(): array
    {
        return [
            'name.required' => 'Enter a name for the route.',
            'name.max' => 'The route name can be at most 60 characters.',
            'store_ids.*.exists' => 'Only active stores can be on a route.',
            'store_ids.*.distinct' => 'A store can only be picked once.',
        ];
    }

    private function shape(StoreRoute $r): array
    {
        return [
            'id' => $r->id,
            'name' => $r->name,
            'driver' => $r->driver ? ['id' => $r->driver->id, 'name' => $r->driver->name] : null,
            'effective_from' => optional($r->effective_from)->toDateString(),
            'effective_to' => optional($r->effective_to)->toDateString(),
            'stores' => $r->stores->map(fn ($s) => $s->only(['id', 'name', 'branch_code', 'customer_code', 'city', 'chain', 'customer_type']))->values(),
        ];
    }
}
