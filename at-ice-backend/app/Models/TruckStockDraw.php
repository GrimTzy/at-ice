<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Part of a delivered quantity and the truck batch it came from (null when no
// loading count covered it).
class TruckStockDraw extends Model
{
    protected $fillable = [
        'batch_id', 'delivery_id', 'driver_id', 'product_type', 'quantity', 'drawn_at',
    ];

    protected $casts = [
        'drawn_at' => 'datetime',
        'quantity' => 'integer',
    ];

    public function batch()
    {
        return $this->belongsTo(TruckStockBatch::class, 'batch_id');
    }
}
