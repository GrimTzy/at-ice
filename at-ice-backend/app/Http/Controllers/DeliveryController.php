<?php
namespace App\Http\Controllers;

use App\Models\Delivery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DeliveryController extends Controller
{
    public function index()
    {
        return response()->json(
            Delivery::with(['driver', 'customer', 'supplier', 'items.product', 'proofs'])->get()
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'delivery_no'              => 'required|unique:deliveries',
            'driver_id'                => 'required|exists:users,id',
            'customer_id'              => 'nullable|exists:users,id',
            'delivery_type'            => 'required|in:outbound,inbound',
            'supplier_id'              => 'nullable|exists:suppliers,id',
            'scheduled_date'           => 'required|date',
            'notes'                    => 'nullable|string',
            'items'                    => 'required|array|min:1',
            'items.*.product_id'       => 'required|exists:products,id',
            'items.*.quantity_ordered' => 'required|integer|min:1',
            'items.*.unit_price'       => 'required|numeric|min:0',
        ]);

        $data['prepared_by'] = Auth::id();
        $items = $data['items'];
        unset($data['items']);

        $delivery = Delivery::create($data);

        foreach ($items as $item) {
            $delivery->items()->create($item);
        }

        return response()->json($delivery->load('items.product'), 201);
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