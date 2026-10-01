<?php

namespace App\Http\Controllers;

use App\Models\InventoryAlert;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StockOutRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// Flow (matches the paper's scope):
//   Maintenance requests an inventory item  ->  Admin approves or rejects
//   ->  Checker releases the item, which deducts the stock and records a
//       confirmed Stock Out in the stock movement history.
class StockOutRequestController extends Controller
{
    private const RELATIONS = [
        'requestedBy:id,name',
        'approvedBy:id,name',
        'releasedBy:id,name',
        'product:id,product_code,name,unit,current_stock,minimum_stock',
    ];

    // Maintenance sees only their own requests. Checker and Admin see all.
    public function index(Request $request)
    {
        $user = $request->user();

        $query = StockOutRequest::with(self::RELATIONS)->orderBy('created_at', 'desc');

        if ($user->role === 'employee' && $user->sub_role === 'maintenance') {
            $query->where('requested_by', $user->id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return response()->json($query->paginate(50));
    }

    // Maintenance: inventory items that can be requested (read only).
    public function items()
    {
        return response()->json(
            Product::orderBy('name')->get(['id', 'product_code', 'name', 'category', 'unit', 'current_stock'])
        );
    }

    // Maintenance: submit a new stock-out request for an inventory item.
    public function store(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity'   => 'required|integer|min:1',
            'purpose'    => 'required|string|max:1000',
        ], [
            'product_id.required' => 'Select the item you need from the inventory list.',
            'product_id.exists'   => 'The selected item no longer exists in the inventory.',
        ]);

        $product = Product::findOrFail($validated['product_id']);

        if ($product->current_stock < $validated['quantity']) {
            return response()->json([
                'message' => "Not enough stock. Available: {$product->current_stock} {$product->unit}.",
                'errors'  => ['quantity' => ["Not enough stock. Available: {$product->current_stock} {$product->unit}."]],
            ], 422);
        }

        $stockOutRequest = StockOutRequest::create([
            'requested_by' => $request->user()->id,
            'product_id'   => $product->id,
            'item_name'    => $product->name, // kept for display and older records
            'quantity'     => $validated['quantity'],
            'purpose'      => $validated['purpose'],
            'status'       => 'pending',
        ]);

        return response()->json([
            'message' => 'Stock-out request submitted. Waiting for Admin approval.',
            'request' => $stockOutRequest->load(self::RELATIONS),
        ], 201);
    }

    public function show(StockOutRequest $stockOutRequest)
    {
        return response()->json($stockOutRequest->load(self::RELATIONS));
    }

    // Admin: approve a pending request.
    public function approve(StockOutRequest $stockOutRequest)
    {
        if ($stockOutRequest->status !== 'pending') {
            return response()->json(['message' => 'Only pending requests can be approved.'], 422);
        }

        $stockOutRequest->update([
            'status'      => 'approved',
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);

        return response()->json([
            'message' => 'Request approved. The Checker will release the item.',
            'request' => $stockOutRequest->fresh()->load(self::RELATIONS),
        ]);
    }

    // Admin: reject a pending request.
    public function reject(Request $request, StockOutRequest $stockOutRequest)
    {
        if ($stockOutRequest->status !== 'pending') {
            return response()->json(['message' => 'Only pending requests can be rejected.'], 422);
        }

        $validated = $request->validate([
            'rejection_reason' => 'required|string|max:1000',
        ]);

        $stockOutRequest->update([
            'status'           => 'rejected',
            'approved_by'      => auth()->id(),
            'approved_at'      => now(),
            'rejection_reason' => $validated['rejection_reason'],
        ]);

        return response()->json([
            'message' => 'Request rejected.',
            'request' => $stockOutRequest->fresh()->load(self::RELATIONS),
        ]);
    }

    // Checker: release an approved request. Deducts the stock and records a
    // confirmed Stock Out (entered by the Maintenance requester, confirmed by
    // the releasing Checker) so the inventory history stays complete.
    public function release(Request $request, StockOutRequest $stockOutRequest)
    {
        if ($stockOutRequest->status !== 'approved') {
            return response()->json(['message' => 'Only approved requests can be released.'], 422);
        }

        if (!$stockOutRequest->product_id) {
            return response()->json([
                'message' => 'This request is not linked to an inventory item, so it cannot be released. Ask Maintenance to submit a new request.',
            ], 422);
        }

        $user = $request->user();

        // Only a Checker releases items from the stockroom (not Admin, not Maintenance).
        if (!($user->role === 'employee' && $user->sub_role === 'checker')) {
            return response()->json(['message' => 'Only a Checker can release stock-out requests.'], 403);
        }

        $checkerId = $user->id;

        if ((int) $stockOutRequest->requested_by === (int) $checkerId) {
            return response()->json(['message' => 'You cannot release your own request.'], 403);
        }

        try {
            DB::transaction(function () use ($stockOutRequest, $checkerId) {
                $product = Product::lockForUpdate()->findOrFail($stockOutRequest->product_id);

                if ($product->current_stock < $stockOutRequest->quantity) {
                    throw new \RuntimeException(
                        "Not enough stock to release. Available: {$product->current_stock} {$product->unit}."
                    );
                }

                $before = $product->current_stock;
                $after  = $before - $stockOutRequest->quantity;

                $movement = StockMovement::create([
                    'product_id'   => $product->id,
                    'type'         => 'stock_out',
                    'quantity'     => $stockOutRequest->quantity,
                    'stock_before' => $before,
                    'stock_after'  => $after,
                    'reference_no' => 'SOR-' . str_pad($stockOutRequest->id, 4, '0', STR_PAD_LEFT),
                    'source'       => 'maintenance',
                    'reason'       => 'Maintenance request: ' . $stockOutRequest->purpose,
                    'status'       => 'confirmed',
                    'entered_by'   => $stockOutRequest->requested_by,
                    'confirmed_by' => $checkerId,
                    'confirmed_at' => now(),
                ]);

                $product->update(['current_stock' => $after]);

                if ($product->isLowStock()) {
                    InventoryAlert::firstOrCreate(
                        ['product_id' => $product->id, 'alert_type' => 'low_stock', 'is_resolved' => false],
                        ['message' => "{$product->name} is low on stock. Current: {$after} {$product->unit}(s)."]
                    );
                }

                $stockOutRequest->update([
                    'status'            => 'released',
                    'released_by'       => $checkerId,
                    'released_at'       => now(),
                    'stock_movement_id' => $movement->id,
                ]);
            });
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Item released and stock updated.',
            'request' => $stockOutRequest->fresh()->load(self::RELATIONS),
        ]);
    }
}