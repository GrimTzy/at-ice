<?php

namespace App\Http\Controllers;

use App\Models\StockOutRequest;
use Illuminate\Http\Request;

class StockOutRequestController extends Controller
{
    // Maintenance: view own/all requests (paginated, filterable by status)
    public function index(Request $request)
    {
        $query = StockOutRequest::with(['requestedBy:id,name', 'approvedBy:id,name', 'releasedBy:id,name'])
            ->orderBy('created_at', 'desc');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return response()->json($query->paginate(20));
    }

    // Maintenance: submit a new stock-out request
    public function store(Request $request)
    {
        $validated = $request->validate([
            'item_name' => 'required|string|max:255',
            'quantity'  => 'required|integer|min:1',
            'purpose'   => 'required|string',
        ]);

        $stockOutRequest = StockOutRequest::create([
            ...$validated,
            'requested_by' => auth()->id(),
            'status' => 'pending',
        ]);

        return response()->json([
            'message' => 'Stock-out request submitted.',
            'request' => $stockOutRequest->load('requestedBy:id,name'),
        ], 201);
    }

    public function show(StockOutRequest $stockOutRequest)
    {
        return response()->json(
            $stockOutRequest->load(['requestedBy:id,name', 'approvedBy:id,name', 'releasedBy:id,name'])
        );
    }

    // Admin: approve a pending request
    public function approve(StockOutRequest $stockOutRequest)
    {
        if ($stockOutRequest->status !== 'pending') {
            return response()->json(['message' => 'Only pending requests can be approved.'], 422);
        }

        $stockOutRequest->update([
            'status' => 'approved',
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);

        return response()->json([
            'message' => 'Request approved.',
            'request' => $stockOutRequest->fresh()->load(['requestedBy:id,name', 'approvedBy:id,name']),
        ]);
    }

    // Admin: reject a pending request
    public function reject(Request $request, StockOutRequest $stockOutRequest)
    {
        if ($stockOutRequest->status !== 'pending') {
            return response()->json(['message' => 'Only pending requests can be rejected.'], 422);
        }

        $validated = $request->validate([
            'rejection_reason' => 'required|string',
        ]);

        $stockOutRequest->update([
            'status' => 'rejected',
            'approved_by' => auth()->id(),
            'approved_at' => now(),
            'rejection_reason' => $validated['rejection_reason'],
        ]);

        return response()->json([
            'message' => 'Request rejected.',
            'request' => $stockOutRequest->fresh()->load(['requestedBy:id,name', 'approvedBy:id,name']),
        ]);
    }

    // Checker: release an approved request
    public function release(StockOutRequest $stockOutRequest)
    {
        if ($stockOutRequest->status !== 'approved') {
            return response()->json(['message' => 'Only approved requests can be released.'], 422);
        }

        $stockOutRequest->update([
            'status' => 'released',
            'released_by' => auth()->id(),
            'released_at' => now(),
        ]);

        return response()->json([
            'message' => 'Item released.',
            'request' => $stockOutRequest->fresh()->load(['requestedBy:id,name', 'approvedBy:id,name', 'releasedBy:id,name']),
        ]);
    }
}