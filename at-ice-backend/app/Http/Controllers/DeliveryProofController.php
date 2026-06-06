<?php

namespace App\Http\Controllers;

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
            'receipt'
        ])->latest()->get();

        return response()->json($proofs);
    }

    public function show($id)
    {
        $proof = DeliveryProof::with([
            'delivery.customer',
            'salesman',
            'receipt'
        ])->findOrFail($id);

        return response()->json($proof);
    }

    public function confirm(Request $request)
    {
        $request->validate([
            'delivery_id' => 'required|exists:deliveries,id',
            'quantity'    => 'required|integer|min:1',
            'photo'       => 'required|image|mimes:jpg,jpeg,png|max:5120', // max 5MB
            'unit_price'  => 'required|numeric|min:0',
        ]);

        $alreadyConfirmed = DeliveryProof::where('delivery_id', $request->delivery_id)->exists();
        if ($alreadyConfirmed) {
            return response()->json([
                'message' => 'This delivery has already been confirmed.',
            ], 422);
        }

        DB::beginTransaction();

        try {
            $photoPath = $request->file('photo')->store('delivery_proofs', 'public');

            $proof = DeliveryProof::create([
                'delivery_id' => $request->delivery_id,
                'salesman_id' => auth()->id(),
                'quantity'    => $request->quantity,
                'photo_path'  => $photoPath,
                'confirmed_at'=> now(),
            ]);

            $delivery = Delivery::with('customer')->findOrFail($request->delivery_id);

            $totalAmount = $request->quantity * $request->unit_price;

            $receipt = Receipt::create([
                'receipt_no'        => Receipt::generateReceiptNo(),
                'delivery_id'       => $request->delivery_id,
                'delivery_proof_id' => $proof->id,
                'customer_id'       => $delivery->customer_id,
                'salesman_id'       => auth()->id(),
                'quantity'          => $request->quantity,
                'unit_price'        => $request->unit_price,
                'total_amount'      => $totalAmount,
                'payment_status'    => 'unpaid',
            ]);

            $delivery->update(['status' => 'delivered']);

            DB::commit();

            return response()->json([
                'message' => 'Delivery confirmed successfully!',
                'proof'   => $proof,
                'receipt' => $receipt,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Something went wrong. Please try again.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}