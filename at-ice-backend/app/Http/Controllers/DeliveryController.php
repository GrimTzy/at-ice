<?php
namespace App\Http\Controllers;

use App\Events\DeliveryStatusUpdated;
use App\Models\Delivery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DeliveryController extends Controller
{
    public function index()
    {
        return response()->json(
            Delivery::with(['driver', 'customer', 'supplier', 'items.product', 'proofs'])->latest()->get()
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'driver_id'                => 'required|exists:users,id',
            'customer_id'              => 'nullable|exists:customers,id',
            'delivery_type'            => 'required|in:outbound,inbound',
            'supplier_id'              => 'nullable|exists:suppliers,id',
            'scheduled_date'           => 'required|date',
            'notes'                    => 'nullable|string',
            'items'                    => 'required|array|min:1',
            'items.*.product_id'       => 'required|exists:products,id',
            'items.*.quantity_ordered' => 'required|integer|min:1',
            'items.*.unit_price'       => 'required|numeric|min:0',
        ]);

        // Sequential delivery number: DEL-0001, DEL-0002, ...
        // Uses the highest existing number (including soft deleted rows)
        // instead of a row count, so deleting a delivery never causes a
        // duplicate number.
        $last = Delivery::withTrashed()
            ->where('delivery_no', 'REGEXP', '^DEL-[0-9]{4}$')
            ->orderByDesc('delivery_no')
            ->value('delivery_no');

        $next = $last ? ((int) substr($last, 4)) + 1 : 1;
        $data['delivery_no'] = 'DEL-' . str_pad($next, 4, '0', STR_PAD_LEFT);

        $data['prepared_by'] = Auth::id();
        $items = $data['items'];
        unset($data['items']);

        $delivery = Delivery::create($data);

        foreach ($items as $item) {
            $delivery->items()->create($item);
        }

        return response()->json($delivery->load(['driver', 'customer', 'items.product']), 201);
    }

    public function show($id)
    {
        return response()->json(
            Delivery::with(['driver', 'customer', 'supplier', 'items.product', 'proofs', 'preparedBy'])->findOrFail($id)
        );
    }

    public function cancel($id)
    {
        $delivery = Delivery::findOrFail($id);

        if ($delivery->status === 'delivered') {
            return response()->json(['message' => 'Cannot cancel a delivered delivery.'], 422);
        }

        $delivery->update(['status' => 'cancelled']);
        return response()->json(['message' => 'Delivery cancelled']);
    }

    // Driver: can only START a delivery (pending to in_transit).
    // "Delivered" is set by the Salesman through DeliveryProofController@confirm.
    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:in_transit',
        ]);

        $delivery = Delivery::with(['customer', 'items.product', 'proofs'])->findOrFail($id);

        if ($delivery->driver_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        if ($delivery->status !== 'pending') {
            return response()->json(['message' => 'Only pending deliveries can be started.'], 422);
        }

        $delivery->update([
            'status'        => 'in_transit',
            'dispatched_at' => now(),
        ]);

        broadcast(new DeliveryStatusUpdated([
            'delivery_id' => $delivery->id,
            'delivery_no' => $delivery->delivery_no,
            'driver_id'   => $delivery->driver_id,
            'customer'    => $delivery->customer?->name ?? 'Unknown',
            'status'      => 'in_transit',
            'message'     => "Delivery {$delivery->delivery_no} is now in_transit.",
        ]))->toOthers();

        return response()->json([
            'message'  => 'Delivery status updated!',
            'delivery' => $delivery,
        ]);
    }

    // Salesman: view assigned deliveries (not yet confirmed)
    public function myDeliveries(Request $request)
    {
        $deliveries = Delivery::with(['driver', 'customer', 'items.product'])
            ->where('status', 'pending')
            ->whereDoesntHave('proofs')
            ->get();

        return response()->json($deliveries);
    }

    // Driver: view assigned deliveries
    public function driverDeliveries(Request $request)
    {
        $deliveries = Delivery::with(['customer', 'items.product', 'proofs'])
            ->where('driver_id', $request->user()->id)
            ->whereIn('status', ['pending', 'in_transit', 'delivered'])
            ->orderBy('scheduled_date', 'asc')
            ->get();

        return response()->json($deliveries);
    }
}