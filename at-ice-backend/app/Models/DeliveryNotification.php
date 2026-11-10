<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// A notice sent to a store about one of its deliveries, per channel (portal,
// email, sms). kind: delay (may be late, once per delivery), driver_changed,
// cancelled.
class DeliveryNotification extends Model
{
    protected $fillable = ['customer_id', 'delivery_id', 'channel', 'kind', 'message', 'sent_at'];

    protected $casts = ['sent_at' => 'datetime'];

    public function delivery()
    {
        return $this->belongsTo(Delivery::class);
    }
}
