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

        // Auto-generate a sequential delivery_no (DEL-0001, DEL-0002, ...)
        // instead of requiring the frontend to supply one or using a random
        // uniqid() — per team decision, delivery numbers should reflect
        // "which delivery number this is" in order.
        $count = Delivery::count() + 1;
        $data['delivery_no'] = 'DEL-' . str_pad($count, 4, '0', STR_PAD_LEFT);

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

    // Driver — update delivery status (pending → in_transit → delivered)
    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:in_transit,delivered',
        ]);

        $delivery = Delivery::with(['customer', 'items.product', 'proofs'])->findOrFail($id);

        if ($delivery->driver_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $delivery->update(['status' => $request->status]);

        broadcast(new DeliveryStatusUpdated([
            'delivery_id'  => $delivery->id,
            'delivery_no'  => $delivery->delivery_no,
            'driver_id'    => $delivery->driver_id,
            'customer'     => $delivery->customer?->name ?? 'Unknown',
            'status'       => $request->status,
            'message'      => "Delivery {$delivery->delivery_no} is now {$request->status}.",
        ]))->toOthers();

        return response()->json([
            'message'  => 'Delivery status updated!',
            'delivery' => $delivery,
        ]);
    }

    // Salesman — view assigned deliveries
    public function myDeliveries(Request $request)
    {
        $deliveries = Delivery::with(['driver', 'customer', 'items.product'])
            ->where('status', 'pending')
            ->whereDoesntHave('proofs')
            ->get();

        return response()->json($deliveries);
    }

    // Driver — view assigned deliveries
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