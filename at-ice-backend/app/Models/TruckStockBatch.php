<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// One loading count of one product on a Driver's truck. quantity_left goes
// down as stops are delivered and never resets at the end of a trip: the ice
// stays on the refrigerated truck (App\Support\TruckStock).
class TruckStockBatch extends Model
{
    protected $fillable = [
        'driver_id', 'product_type', 'quantity_loaded', 'quantity_left',
        'loaded_at', 'ice_loading_id', 'delivery_id',
    ];

    protected $casts = [
        'loaded_at' => 'datetime',
        'quantity_loaded' => 'integer',
        'quantity_left' => 'integer',
    ];

    public function driver()
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function draws()
    {
        return $this->hasMany(TruckStockDraw::class, 'batch_id');
    }
}
