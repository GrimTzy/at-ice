<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'customer_code', 'name', 'branch_code', 'address', 'city',
        'province', 'latitude', 'longitude', 'contact_person', 'contact_number', 'email',
        'mop', 'is_active', 'notes'
    ];

    public function orders() {
        return $this->hasMany(Order::class);
    }

    public function payments() {
        return $this->hasMany(Payment::class);
    }

    public function customerPrices() {
        return $this->hasMany(CustomerPrice::class);
    }
}