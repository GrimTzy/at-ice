<?php

namespace App\Http\Controllers;

use App\Models\Delivery;
use App\Models\DeliveryContract;
use App\Support\Audit;
use App\Support\DeliveryDispatch;
use App\Support\DriverAvailability;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

// Admin > CRM > Customers > Contracts: one delivery schedule per customer (a whole
// chain such as SM, or a single store) with a date range and delivery days.
// Approving it makes the daily deliveries (App\Support\DeliveryDispatch).
class DeliveryContractController extends Controller
{
    public function index()
    {
        $contracts = DeliveryContract::with(['stores:customers.id,customers.name,customers.chain,customers.branch_code,customers.customer_type', 'approvedBy:id,name'])
            ->withCount(['deliveries', 'deliveries as delivered_count' => fn ($q) => $q->where('status', 'delivered')])
            ->latest()
            ->get();

        return response()->json($contracts->map(fn ($c) => $c->toArray() + [
            'planned' => count(DeliveryDispatch::dates($c)) * $c->stores->count(),
        ]));
    }

    // The ID the next contract for these stores would get, shown in the form
    // before saving. It is only taken on save, so it may move up by one if
    // another contract for the same customer is saved first.
    public function nextNumber(Request $request)
    {
        $data = $request->validate([
            'chain' => 'nullable|string|max:50',
            'store_ids' => 'required|array|min:1',
            'store_ids.*' => 'integer|exists:customers,id',
        ]);

        return response()->json(['contract_no' => DeliveryContract::nextNumber($data['chain'] ?? null, $data['store_ids'])]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:50',
            'chain' => 'nullable|string|max:50',
            'store_ids' => 'required|array|min:1',
            'store_ids.*' => 'integer|distinct|exists:customers,id',
            'start_date' => 'required|date|after_or_equal:'.DriverAvailability::today(),
            'end_date' => 'required|date|after_or_equal:start_date|before_or_equal:'.now(config('atice.timezone'))->addYear()->toDateString(),
            'delivery_days' => 'required|array|min:1',
            'delivery_days.*' => ['string', 'distinct', Rule::in(DeliveryContract::DAYS)],
            // the time window: from (optional) to Deliver by, a deadline
            'delivery_from' => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'delivery_time' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'items' => 'required|array|min:1',
            'items.*.product_type' => 'required|distinct|in:bags,small_sack,big_sack',
            'items.*.quantity' => 'required|integer|min:1|max:'.config('atice.max_product_quantity'),
            'notes' => 'nullable|string|max:1000',
        ], [
            'store_ids.required' => 'Pick at least one store.',
            'delivery_days.required' => 'Pick at least one delivery day.',
            'end_date.before_or_equal' => 'A contract can run for at most one year.',
            'delivery_from.regex' => 'Pick the time the window opens.',
            'items.*.product_type.distinct' => 'Each product can only be added once.',
        ]);

        $this->checkWindow($data);

        // One contract per store (owner, 2026-11-11): a store that has one running
        // or coming up is edited instead of getting a second.
        $existing = $this->runningFor($data['store_ids']);
        if ($existing) {
            return response()->json(['message' => $existing, 'errors' => ['store_ids' => [$existing]]], 422);
        }

        // Admin makes the contract, so it is approved at once: the daily deliveries
        // are made now (owner, 2026-11-11). No price for a store stops it before anything is saved.
        $result = [];
        $contract = DB::transaction(function () use ($data, $request, &$result) {
            $contract = DeliveryContract::create([
                'contract_no' => DeliveryContract::nextNumber($data['chain'] ?? null, $data['store_ids']),
                'name' => $data['name'],
                'chain' => $data['chain'] ?? null,
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                // kept in Mon..Sun order
                'delivery_days' => array_values(array_intersect(DeliveryContract::DAYS, $data['delivery_days'])),
                'delivery_time' => $data['delivery_time'].':00',
                'delivery_from' => ! empty($data['delivery_from']) ? $data['delivery_from'].':00' : null,
                'items' => array_map(fn ($i) => ['product_type' => $i['product_type'], 'quantity' => (int) $i['quantity']], $data['items']),
                'notes' => $data['notes'] ?? null,
                'status' => 'pending',
                'created_by' => $request->user()->id,
            ]);
            $contract->stores()->sync($data['store_ids']);
            $contract->load('stores');

            $missing = DeliveryDispatch::missingPrices($contract);
            if ($missing) {
                $list = collect($missing)->map(fn ($types, $store) => $store.' ('.implode(', ', $types).')')->implode('; ');
                throw new HttpResponseException(response()->json([
                    'message' => "No price is set for: {$list}. Set them in Customer Prices first.",
                    'missing_prices' => $missing,
                ], 422));
            }

            $contract->update(['status' => 'approved', 'approved_by' => $request->user()->id, 'approved_at' => now()]);
            $result = DeliveryDispatch::generate($contract, $request->user()->id);

            return $contract;
        });

        Audit::log('delivery_contract.created', $contract, null, $contract->only(['contract_no', 'name', 'start_date', 'end_date', 'delivery_days']) + ['status' => 'approved'] + $result);

        $text = "{$result['created']} deliveries were scheduled.";
        if ($result['skipped']) {
            $text .= " {$result['skipped']} were skipped because the store already has a delivery that day.";
        }

        return response()->json($contract->load('stores:customers.id,customers.name')->toArray() + ['message' => $text], 201);
    }

    // Admin approves: the daily deliveries are generated at once.
    public function approve(Request $request, $id)
    {
        $contract = DeliveryContract::with('stores')->findOrFail($id);
        if ($contract->status !== 'pending') {
            return response()->json(['message' => 'Only a pending contract can be approved.'], 422);
        }
        if ($contract->end_date->toDateString() < DriverAvailability::today()) {
            return response()->json(['message' => 'This contract has already ended. Make a new one with new dates.'], 422);
        }

        $missing = DeliveryDispatch::missingPrices($contract);
        if ($missing) {
            $list = collect($missing)->map(fn ($types, $store) => $store.' ('.implode(', ', $types).')')->implode('; ');

            return response()->json([
                'message' => "No price is set for: {$list}. Set them in Customer Prices first.",
                'missing_prices' => $missing,
            ], 422);
        }

        $contract->update(['status' => 'approved', 'approved_by' => $request->user()->id, 'approved_at' => now()]);
        $result = DeliveryDispatch::generate($contract, $request->user()->id);
        Audit::log('delivery_contract.approved', $contract, ['status' => 'pending'], ['status' => 'approved'] + $result);

        $text = "{$result['created']} deliveries were scheduled.";
        if ($result['skipped']) {
            $text .= " {$result['skipped']} were skipped because the store already has a delivery that day.";
        }

        return response()->json(['message' => $text] + $result);
    }

    // The window must open before its Deliver by time.
    private function checkWindow(array $data): void
    {
        if (! empty($data['delivery_from']) && $data['delivery_from'] >= $data['delivery_time']) {
            throw \Illuminate\Validation\ValidationException::withMessages(['delivery_from' => ['The window must open before the Deliver by time.']]);
        }
    }

    // Edit the store's contract (owner, 2026-11-11: one contract per store, editable).
    // The upcoming deliveries that have not started are made again from the new
    // details; deliveries in transit or delivered stay as they are.
    public function update(Request $request, $id)
    {
        $contract = DeliveryContract::with('stores')->findOrFail($id);
        if ($contract->status === 'cancelled') {
            return response()->json(['message' => 'A cancelled contract cannot be edited. Make a new one.'], 422);
        }

        $data = $request->validate([
            'name' => 'required|string|max:50',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date|before_or_equal:'.now(config('atice.timezone'))->addYear()->toDateString(),
            'delivery_days' => 'required|array|min:1',
            'delivery_days.*' => ['string', 'distinct', Rule::in(DeliveryContract::DAYS)],
            // the time window: from (optional) to Deliver by, a deadline
            'delivery_from' => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'delivery_time' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'items' => 'required|array|min:1',
            'items.*.product_type' => 'required|distinct|in:bags,small_sack,big_sack',
            'items.*.quantity' => 'required|integer|min:1|max:'.config('atice.max_product_quantity'),
            'notes' => 'nullable|string|max:1000',
        ], [
            'delivery_days.required' => 'Pick at least one delivery day.',
            'end_date.before_or_equal' => 'A contract can run for at most one year.',
            'delivery_from.regex' => 'Pick the time the window opens.',
            'items.*.product_type.distinct' => 'Each product can only be added once.',
        ]);

        $this->checkWindow($data);
        $before = $contract->only(['name', 'start_date', 'end_date', 'delivery_days', 'delivery_from', 'delivery_time', 'items']);
        $result = [];
        $cancelled = 0;
        DB::transaction(function () use ($contract, $data, $request, &$result, &$cancelled) {
            $contract->fill([
                'name' => $data['name'],
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'delivery_days' => array_values(array_intersect(DeliveryContract::DAYS, $data['delivery_days'])),
                'delivery_time' => $data['delivery_time'].':00',
                'delivery_from' => ! empty($data['delivery_from']) ? $data['delivery_from'].':00' : null,
                'items' => array_map(fn ($i) => ['product_type' => $i['product_type'], 'quantity' => (int) $i['quantity']], $data['items']),
                'notes' => $data['notes'] ?? null,
            ]);

            $missing = DeliveryDispatch::missingPrices($contract);
            if ($missing) {
                $list = collect($missing)->map(fn ($types, $store) => $store.' ('.implode(', ', $types).')')->implode('; ');
                throw new HttpResponseException(response()->json([
                    'message' => "No price is set for: {$list}. Set them in Customer Prices first.",
                    'missing_prices' => $missing,
                ], 422));
            }

            $cancelled = Delivery::where('contract_id', $contract->id)
                ->whereIn('status', ['pending', 'queued'])
                ->whereDate('scheduled_date', '>=', DriverAvailability::today())
                ->update(['status' => 'cancelled', 'cancel_reason' => "Contract {$contract->contract_no} edited", 'updated_at' => now()]);
            $contract->save();
            $result = DeliveryDispatch::generate($contract, $request->user()->id);
        });
        Audit::log('delivery_contract.updated', $contract, $before, $contract->only(['name', 'start_date', 'end_date', 'delivery_days', 'delivery_time', 'items']) + $result);

        return response()->json($contract->load('stores:customers.id,customers.name')->toArray() + [
            'message' => "Contract saved. {$result['created']} upcoming deliveries were made again from the new details.",
        ]);
    }

    // The message to show when one of these stores already has a contract that
    // is running or has not ended, else null.
    private function runningFor(array $storeIds): ?string
    {
        $c = DeliveryContract::where('status', '!=', 'cancelled')
            ->whereDate('end_date', '>=', DriverAvailability::today())
            ->whereHas('stores', fn ($q) => $q->whereIn('customers.id', $storeIds))
            ->with(['stores' => fn ($q) => $q->whereIn('customers.id', $storeIds)])
            ->first();
        if (! $c) {
            return null;
        }

        return ($c->stores->first()?->name ?? 'This store')." already has a contract ({$c->contract_no}). Edit that one instead of making another.";
    }
    // Cancels the contract and its deliveries from today on that have not
    // started yet. Deliveries in transit or delivered are kept.
    public function cancel($id)
    {
        $contract = DeliveryContract::findOrFail($id);
        if ($contract->status === 'cancelled') {
            return response()->json(['message' => 'This contract is already cancelled.'], 422);
        }

        $count = Delivery::where('contract_id', $contract->id)
            ->whereIn('status', ['pending', 'queued'])
            ->whereDate('scheduled_date', '>=', DriverAvailability::today())
            ->update(['status' => 'cancelled', 'cancel_reason' => "Contract {$contract->contract_no} cancelled", 'updated_at' => now()]);
        $before = $contract->status;
        $contract->update(['status' => 'cancelled']);
        Audit::log('delivery_contract.cancelled', $contract, ['status' => $before], ['status' => 'cancelled', 'deliveries_cancelled' => $count]);

        return response()->json(['message' => "Contract cancelled. {$count} upcoming deliveries were cancelled."]);
    }
}
