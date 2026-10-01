<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockOutRequest extends Model
{
    protected $fillable = [
        'requested_by',
        'product_id',
        'item_name',
        'quantity',
        'purpose',
        'status',
        'approved_by',
        'approved_at',
        'rejection_reason',
        'released_by',
        'released_at',
        'stock_movement_id',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
        'released_at' => 'datetime',
    ];

    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function releasedBy()
    {
        return $this->belongsTo(User::class, 'released_by');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function stockMovement()
    {
        return $this->belongsTo(StockMovement::class);
    }
}