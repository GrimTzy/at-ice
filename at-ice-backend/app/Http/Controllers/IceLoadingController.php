<?php

namespace App\Http\Controllers;

use App\Models\IceLoading;
use Illuminate\Http\Request;

class IceLoadingController extends Controller
{
    // GET /api/ice-loadings
    public function index(Request $request)
    {
        $query = IceLoading::with(['delivery.customer', 'checker:id,name'])
            ->orderBy('loaded_at', 'desc');

        if ($request->filled('delivery_id')) {
            $query->where('delivery_id', $request->delivery_id);
        }

        return response()->json($query->paginate(20));
    }

    //POST /api/ice-loadings
    public function store(Request $request)
    {
        $validated = $request->validate([
            'delivery_id'      => 'required|exists:deliveries,id',
            'quantity_loaded'  => 'required|integer|min:1',
            'loaded_at'        => 'required|date',
        ]);

        $loading = IceLoading::create([
            ...$validated,
            'checker_id' => auth()->id(),
        ]);

        return response()->json([
            'message' => 'Ice loading count recorded.',
            'loading' => $loading->load(['delivery.customer', 'checker:id,name']),
        ], 201);
    }

    // GET /api/ice-loadings/{iceLoading}
    public function show(IceLoading $iceLoading)
    {
        return response()->json($iceLoading->load(['delivery.customer', 'checker:id,name']));
    }
}