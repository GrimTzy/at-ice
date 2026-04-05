<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeliveryProof extends Model
{
    protected $fillable = [
        'delivery_id', 'uploaded_by', 'photo_path',
        'latitude', 'longitude', 'is_within_geofence',
        'backload_quantity', 'backload_notes',
        'notes', 'captured_at'
    ];

    protected $casts = [
        'is_within_geofence' => 'boolean',
        'captured_at'        => 'datetime',
    ];

    public function delivery()   { return $this->belongsTo(Delivery::class); }
    public function uploadedBy() { return $this->belongsTo(User::class, 'uploaded_by'); }
}