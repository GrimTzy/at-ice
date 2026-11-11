<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// A named group of stores (Route A, Route B...). A store is on one route at
// most (customers.store_route_id). Admin hands a route to a Driver in the
// Driver Stores tab; its stores then follow that Driver.
class StoreRoute extends Model
{
    protected $fillable = ['name', 'driver_id', 'effective_from', 'effective_to'];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to' => 'date',
    ];

    public function stores()
    {
        return $this->hasMany(Customer::class, 'store_route_id');
    }

    public function driver()
    {
        return $this->belongsTo(User::class, 'driver_id');
    }
}
