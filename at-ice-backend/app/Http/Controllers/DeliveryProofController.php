<?php
namespace App\Http\Controllers;

use App\Models\Delivery;
use App\Models\DeliveryProof;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\InventoryAlert;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DeliveryProofController extends Controller
{
    public function store(Request $request, $deliveryId)
    {
        $delivery = Delivery::with('items.product')->findOrFail($deliveryId);

        // Only assigned driver can upload
        if ($delivery->driver_id !== Auth::id()) {
            return response()->json(['message' => 'Unauthorized. You are not the assigned driver.'], 403);
        }

        // Prevent duplicate proof
        if ($delivery->status === 'delivered') {
            return response()->json(['message' => 'Proof already uploaded for this delivery.'], 422);
        }

        $request->validate([
            'photo'              => 'required|image|max:5120',
            'latitude'           => 'nullable|numeric',
            'longitude'          => 'nullable|numeric',
            'is_within_geofence' => 'required|boolean',
            'backload_quantity'  => 'nullable|integer|min:0',
            'backload_notes'     => 'nullable|string',
            'notes'              => 'nullable|string',
            'captured_at'        => 'nullable|date',
        ]);

        // Store photo
        $path = $request->file('photo')->store('delivery_proofs', 'public');

        // Create proof record
        DeliveryProof::create([
            'delivery_id'        => $delivery->id,
            'uploaded_by'        => Auth::id(),
            'photo_path'         => $path,
            'latitude'           => $request->latitude,
            'longitude'          => $request->longitude,
            'is_within_geofence' => $request->is_within_geofence,
            'backload_quantity'  => $request->backload_quantity ?? 0,
            'backload_notes'     => $request->backload_notes,
            'notes'              => $request->notes,
            'captured_at'        => $request->captured_at ?? now(),
        ]);

        // Auto stock movement per item
        foreach ($delivery->items as $item) {
            $product = Product::find($item->product_id);
            if (!$product) continue;

            $stockBefore = $product->current_stock;

            if ($delivery->delivery_type === 'outbound') {
                $newStock    = max(0, $stockBefore - $item->quantity_ordered);
                $movementType = 'stock_out';
            } else {
                $newStock    = $stockBefore + $item->quantity_ordered;
                $movementType = 'stock_in';
            }

            $product->update(['current_stock' => $newStock]);

            StockMovement::create([
                'product_id'   => $product->id,
                'type'         => $movementType,
                'quantity'     => $item->quantity_ordered,
                'stock_before' => $stockBefore,
                'stock_after'  => $newStock,
                'reference_no' => $delivery->delivery_no,
                'source'       => 'delivery_proof',
                'reason'       => 'Auto-' . $movementType . ' via delivery proof upload.',
                'status'       => 'confirmed',
                'entered_by'   => Auth::id(),
                'confirmed_by' => Auth::id(),
                'confirmed_at' => now(),
            ]);

            // Low stock alert (outbound only)
            if ($movementType === 'stock_out' && $product->current_stock <= $product->minimum_stock) {
                InventoryAlert::create([
                    'product_id' => $product->id,
                    'alert_type' => $product->current_stock == 0 ? 'out_of_stock' : 'low_stock',
                    'message'    => "Stock for {$product->name} dropped to {$product->current_stock} after delivery {$delivery->delivery_no}.",
                    'is_resolved' => false,
                ]);
            }

            $item->update(['quantity_delivered' => $item->quantity_ordered]);
        }

        // Update delivery status
        $delivery->update([
            'status'       => 'delivered',
            'delivered_at' => now(),
        ]);

        return response()->json([
            'message'  => 'Proof uploaded. Stock updated. Delivery marked as delivered.',
            'delivery' => $delivery->fresh()->load('proofs', 'items.product'),
        ], 201);
    }

    public function index($deliveryId)
    {
        $delivery = Delivery::findOrFail($deliveryId);
        return response()->json($delivery->proofs()->with('uploadedBy')->get());
    }
}