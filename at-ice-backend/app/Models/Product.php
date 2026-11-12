<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'product_code', 'name', 'category', 'unit',
        'unit_price', 'current_stock', 'minimum_stock',
        'maximum_stock', 'limits_set_at', 'description', 'is_active',
    ];

    protected $casts = ['is_active' => 'boolean', 'limits_set_at' => 'datetime'];

    // Limits of an item added without them (a Checker adds items; only the Admin
    // sets the real limits, and limits_set_at then records it).
    public const DEFAULT_MIN = 10;

    public const DEFAULT_MAX = 1000;

    // Largest limits the Admin can save: 2 digits for the minimum, 5 for the maximum.
    public const MIN_LIMIT_CAP = 99;

    public const MAX_LIMIT_CAP = 99999;

    public function stockMovements()
    {
        return $this->hasMany(StockMovement::class);
    }

    public function alerts()
    {
        return $this->hasMany(InventoryAlert::class);
    }

    public function isLowStock(): bool
    {
        return $this->current_stock <= $this->minimum_stock;
    }
}
