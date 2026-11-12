<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// A phone's push-notification address (see the device_tokens migration).
class DeviceToken extends Model
{
    protected $fillable = ['user_id', 'token', 'platform', 'last_seen_at'];

    protected $casts = ['last_seen_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
