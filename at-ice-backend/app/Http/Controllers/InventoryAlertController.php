<?php

namespace App\Http\Controllers;

use App\Models\InventoryAlert;
use Illuminate\Http\Request;

class InventoryAlertController extends Controller
{
    public function index()
    {
        $alerts = InventoryAlert::with('product')
            ->where('is_resolved', false)
            ->orderBy('created_at', 'desc')
            ->get();
        return response()->json($alerts);
    }

    public function resolve($id)
    {
        $alert = InventoryAlert::findOrFail($id);
        $alert->update([
            'is_resolved' => true,
            'resolved_at' => now()
        ]);
        return response()->json(['message' => 'Alert resolved.']);
    }
}