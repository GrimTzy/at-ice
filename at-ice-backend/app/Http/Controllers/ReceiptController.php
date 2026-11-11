<?php

namespace App\Http\Controllers;

use App\Models\PaymentProof;
use App\Models\Receipt;
use App\Support\Audit;
use App\Support\StoredFiles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReceiptController extends Controller
{
    public function index(Request $request)
    {
        Receipt::markOverdue();

        // 'items' is loaded so the View modal can list each product line
        // (product_type, quantity, unit_price, line_total), and the customer's
        // payment proofs (newest first) so it shows what they attached.
        $query = Receipt::with([
            'delivery',
            'delivery.driver:id,name',
            'customer',
            'salesman',
            'deliveryProof',
            'items',
            'paymentProofs.reviewer:id,name',
        ]);

        if ($request->has('payment_status')) {
            $query->where('payment_status', $request->payment_status);
        }

        if ($request->has('from')) {
            $query->whereDate('created_at', '>=', $request->from);
        }
        if ($request->has('to')) {
            $query->whereDate('created_at', '<=', $request->to);
        }

        $receipts = $query->latest()->get();

        return response()->json($receipts);
    }

    public function show($id)
    {
        $receipt = Receipt::with([
            'delivery',
            'delivery.driver:id,name',
            'customer',
            'salesman',
            'deliveryProof',
            'items',
        ])->findOrFail($id);

        return response()->json($receipt);
    }

    public function markAsPaid(Request $request, $id)
    {
        // Cash needs nothing more; GCash, cheque and bank transfer need a photo
        // or PDF of the payment, kept with the invoice like a customer's proof.
        $request->validate([
            'payment_method' => 'required|in:cash,gcash,check,bank_transfer',
            'proof' => ['required_unless:payment_method,cash', 'nullable', 'file', 'mimes:png,jpg,jpeg,webp,pdf', 'max:5120'],
            'reference_no' => 'nullable|string|max:100',
        ], [
            'proof.required_unless' => 'Attach a photo or PDF of the payment for GCash, cheque or bank transfer.',
            'proof.mimes' => 'The proof must be a photo (JPG, PNG, WEBP) or a PDF.',
            'proof.max' => 'The file is too big. The limit is 5 MB.',
        ]);

        $stored = new StoredFiles('local');
        try {
            return DB::transaction(fn () => $this->markPaid($request, $id, $stored));
        } catch (\Throwable $e) {
            $stored->discard();
            throw $e;
        }
    }

    private function markPaid(Request $request, $id, StoredFiles $stored)
    {
        $receipt = Receipt::lockForUpdate()->findOrFail($id);

        if ($receipt->payment_status === 'paid') {
            return response()->json(['message' => 'This invoice is already paid.'], 422);
        }
        // A customer's proof waiting for review is decided there (approve or
        // reject), so the invoice and its proof never disagree.
        if ($receipt->paymentProofs()->where('status', 'pending')->exists()) {
            return response()->json(['message' => 'The customer sent a payment proof for this invoice. Approve or reject it under Payment proofs to review first.'], 422);
        }

        if ($file = $request->file('proof')) {
            PaymentProof::create([
                'receipt_id' => $receipt->id,
                'customer_id' => $receipt->customer_id,
                'submitted_by' => $request->user()->id,
                'file_path' => $stored->store($file, 'payment-proofs'),
                'file_disk' => 'local',
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                'method' => $request->payment_method,
                'reference_no' => trim((string) $request->reference_no) ?: null,
                'status' => 'approved',
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);
        }

        $before = $receipt->only(['payment_status', 'payment_method', 'paid_at']);
        $receipt->update([
            'payment_status' => 'paid',
            'payment_method' => $request->payment_method,
            'paid_at' => now(),
        ]);

        Audit::log('invoice.marked_paid', $receipt, $before, $receipt->only(['payment_status', 'payment_method']) + [
            'paid_at' => $receipt->paid_at?->toDateTimeString(),
            'proof_attached' => (bool) $request->file('proof'),
        ]);

        return response()->json([
            'message' => 'Invoice marked as paid.',
            'receipt' => $receipt,
        ]);
    }

    public function overdue()
    {
        Receipt::markOverdue();

        return response()->json(
            Receipt::with(['customer', 'delivery'])
                ->where('payment_status', 'overdue')
                ->latest()
                ->get()
        );
    }

    public function summary()
    {
        Receipt::markOverdue();

        $totalSales = Receipt::where('payment_status', 'paid')->sum('total_amount');
        $totalUnpaid = Receipt::where('payment_status', 'unpaid')->sum('total_amount');
        $totalOverdue = Receipt::where('payment_status', 'overdue')->sum('total_amount');
        $receiptCount = Receipt::count();

        return response()->json([
            'total_sales' => $totalSales,
            'total_unpaid' => $totalUnpaid,
            'total_overdue' => $totalOverdue,
            'receipt_count' => $receiptCount,
        ]);
    }
}
