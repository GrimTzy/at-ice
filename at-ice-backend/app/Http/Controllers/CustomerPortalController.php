<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerPrice;
use App\Models\Delivery;
use App\Models\DeliveryProof;
use App\Models\Receipt;
use Illuminate\Http\Request;

// Customer-facing endpoints for the AT-ICE Customer Portal. Every method
// here follows the same shape: look up the Customer record linked to the
// logged-in user via customers.user_id, then scope everything to that one
// customer. This mirrors the fix already applied to
// OrderController@myOrders / PaymentController@myPayments (customer_id on
// these tables refers to a row in the customers table, never the logged-in
// user's own id).
class CustomerPortalController extends Controller
{
    private array $productTypes = ['bags', 'small_sack', 'big_sack'];

    private function resolveCustomer(Request $request): ?Customer
    {
        return Customer::where('user_id', $request->user()->id)->first();
    }

    // GET /api/my-delivery-history
    // (named to avoid colliding with the Salesman's own GET /api/my-deliveries,
    // which is a completely different endpoint — DeliveryProofController@myDeliveries)
    public function myDeliveries(Request $request)
    {
        $customer = $this->resolveCustomer($request);
        if (!$customer) {
            return response()->json(['message' => 'No customer account linked to this login.'], 404);
        }

        $deliveries = Delivery::with(['driver.employee', 'items.product'])
            ->where('customer_id', $customer->id)
            ->orderBy('scheduled_date', 'desc')
            ->get();

        $result = $deliveries->map(function ($delivery) {
            $driverName = $delivery->driver?->name;
            $plate = $delivery->driver?->employee?->vehicle_plate;

            return [
                'id'             => $delivery->id,
                'delivery_no'    => $delivery->delivery_no,
                'status'         => $delivery->status,
                'scheduled_date' => $delivery->scheduled_date,
                'dispatched_at'  => $delivery->dispatched_at,
                'delivered_at'   => $delivery->delivered_at,
                'notes'          => $delivery->notes,
                // Plate number is folded into the display name here, server
                // side, rather than sent as a separate field the frontend
                // has to know how to combine — e.g. "Rhalf Valencia (PN:
                // V7824)". Falls back to the plain name if no plate is set.
                'driver_name'    => $plate ? "{$driverName} (PN: {$plate})" : $driverName,
                'items'          => $delivery->items->map(function ($item) {
                    return [
                        'product_name'       => $item->product?->name,
                        'quantity_ordered'   => $item->quantity_ordered,
                        'quantity_delivered' => $item->quantity_delivered,
                        'unit_price'         => $item->unit_price,
                    ];
                }),
            ];
        });

        return response()->json($result);
    }

    // GET /api/my-receipts
    public function myReceipts(Request $request)
    {
        $customer = $this->resolveCustomer($request);
        if (!$customer) {
            return response()->json(['message' => 'No customer account linked to this login.'], 404);
        }

        $receipts = Receipt::with('items')
            ->where('customer_id', $customer->id)
            ->latest()
            ->get();

        return response()->json($receipts);
    }

    // GET /api/my-delivery-proofs
    public function myDeliveryProofs(Request $request)
    {
        $customer = $this->resolveCustomer($request);
        if (!$customer) {
            return response()->json(['message' => 'No customer account linked to this login.'], 404);
        }

        $proofs = DeliveryProof::with(['delivery', 'salesman'])
            ->whereHas('delivery', fn ($q) => $q->where('customer_id', $customer->id))
            ->latest()
            ->get();

        return response()->json($proofs);
    }

    // GET /api/my-prices
    public function myPrices(Request $request)
    {
        $customer = $this->resolveCustomer($request);
        if (!$customer) {
            return response()->json(['message' => 'No customer account linked to this login.'], 404);
        }

        $rows = CustomerPrice::where('customer_id', $customer->id)->get();

        $prices = collect($this->productTypes)->mapWithKeys(function ($type) use ($rows) {
            $row = $rows->firstWhere('product_type', $type);
            return [$type => $row ? (float) $row->unit_price : null];
        });

        return response()->json([
            'customer_name' => $customer->name,
            'prices'        => $prices,
        ]);
    }
}