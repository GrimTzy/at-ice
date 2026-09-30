<?php

namespace App\Http\Controllers;

use App\Models\CustomerPrice;
use App\Models\Delivery;
use App\Models\DeliveryProof;
use App\Models\Receipt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DeliveryProofController extends Controller
{
    public function index()
    {
        $proofs = DeliveryProof::with([
            'delivery.customer',
            'salesman',
            'receipt.items'
        ])->latest()->get();

        return response()->json($proofs);
    }

    public function show($id)
    {
        $proof = DeliveryProof::with([
            'delivery.customer',
            'salesman',
            'receipt.items'
        ])->findOrFail($id);

        return response()->json($proof);
    }

    public function confirm(Request $request)
    {
        $validated = $request->validate([
            'delivery_id'              => 'required|exists:deliveries,id',
            'products'                 => 'required|array|min:1',
            'products.*.product_type'  => 'required|in:bags,small_sack,big_sack',
            'products.*.quantity'      => 'required|integer|min:1',
            'photo'                    => 'required|image|mimes:jpg,jpeg,png|max:5120',
        ]);

        // Reject duplicate product_type entries in the same submission
        $productTypes = array_column($validated['products'], 'product_type');
        if (count($productTypes) !== count(array_unique($productTypes))) {
            return response()->json([
                'message' => 'Each product type can only be entered once per delivery.',
            ], 422);
        }

        $alreadyConfirmed = DeliveryProof::where('delivery_id', $request->delivery_id)->exists();
        if ($alreadyConfirmed) {
            return response()->json([
                'message' => 'This delivery has already been confirmed.',
            ], 422);
        }

        $delivery = Delivery::with('customer')->findOrFail($request->delivery_id);

        // Look up prices BEFORE starting the transaction / storing the photo,
        // fail fast if any product has no price set for this customer.
        $lineItems     = [];
        $missingPrices = [];

        foreach ($validated['products'] as $line) {
            $unitPrice = CustomerPrice::priceFor($delivery->customer_id, $line['product_type']);

            if ($unitPrice === null) {
                $missingPrices[] = $line['product_type'];
                continue;
            }

            $lineItems[] = [
                'product_type' => $line['product_type'],
                'quantity'     => $line['quantity'],
                'unit_price'   => $unitPrice,
                'line_total'   => round($line['quantity'] * $unitPrice, 2),
            ];
        }

        if (!empty($missingPrices)) {
            return response()->json([
                'message' => 'No price has been set for the following product(s) for this customer: '
                    . implode(', ', $missingPrices)
                    . '. Please ask Admin to set pricing in Customer Prices before confirming.',
                'missing_price_products' => $missingPrices,
            ], 422);
        }

        DB::beginTransaction();

        try {
            $photoPath = $request->file('photo')->store('delivery_proofs', 'public');

            $totalQuantity = array_sum(array_column($lineItems, 'quantity'));
            $totalAmount   = array_sum(array_column($lineItems, 'line_total'));

            $proof = DeliveryProof::create([
                'delivery_id'  => $request->delivery_id,
                'salesman_id'  => auth()->id(),
                'quantity'     => $totalQuantity,
                'photo_path'   => $photoPath,
                'confirmed_at' => now(),
            ]);

            $receipt = Receipt::create([
                'receipt_no'        => Receipt::generateReceiptNo(),
                'delivery_id'       => $request->delivery_id,
                'delivery_proof_id' => $proof->id,
                'customer_id'       => $delivery->customer_id,
                'salesman_id'       => auth()->id(),
                'quantity'          => $totalQuantity,
                'unit_price'        => null, // multi-product, see receipt_items for breakdown
                'total_amount'      => $totalAmount,
                'payment_status'    => 'unpaid',
            ]);

            foreach ($lineItems as $item) {
                $receipt->items()->create($item);
            }

            $delivery->update([
                'status'       => 'delivered',
                'delivered_at' => now(),
            ]);
            event(new \App\Events\DeliveryStatusUpdated($delivery->id, 'delivered'));

            DB::commit();

            return response()->json([
                'message' => 'Delivery confirmed successfully!',
                'proof'   => $proof,
                'receipt' => $receipt->load('items'),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Something went wrong. Please try again.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    // Salesman: deliveries still waiting for confirmation
    // (Driver may or may not have started them yet)
    public function myDeliveries()
    {
        $deliveries = Delivery::with(['customer', 'items.product'])
            ->whereIn('status', ['pending', 'in_transit'])
            ->whereDoesntHave('proofs')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($deliveries);
    }
}