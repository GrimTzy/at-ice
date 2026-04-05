<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeliveryItem extends Model
{
    protected $fillable = [
        'delivery_id', 'product_id',
        'quantity_ordered', 'quantity_delivered',
        'unit_price', 'notes'
    ];

    public function delivery() { return $this->belongsTo(Delivery::class); }
    public function product()  { return $this->belongsTo(Product::class); }
}