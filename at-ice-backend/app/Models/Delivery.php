<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Delivery extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'delivery_no', 'contract_id', 'driver_id', 'reassigned_from_driver_id', 'customer_id', 'delivery_type',
        'supplier_id', 'status', 'scheduled_date', 'ordered_at', 'due_at',
        'dispatched_at', 'delivered_at', 'notes', 'cancel_reason', 'prepared_by',
        'parent_delivery_id', 'is_top_up',
    ];

    // pending: waiting for its Driver to start it; queued: no Driver was free
    // when its Driver went on leave, waits (earliest Deliver by first, then
    // FIFO: oldest ordered_at) for the Driver who finished their list first;
    // in_transit; delivered; cancelled.
    public const STATUSES = ['pending', 'queued', 'in_transit', 'delivered', 'cancelled'];

    protected $casts = [
        'is_top_up' => 'boolean',
        'scheduled_date' => 'date:Y-m-d',
        'ordered_at' => 'datetime',
        'due_at' => 'datetime',
        'dispatched_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    protected $appends = ['timing'];

    // Against its Deliver by time (due_at), a deadline: delivered at or before
    // it is on_time, after it is late; not delivered yet and past it is
    // overdue, otherwise due. minutes: how late, or how long until it is due.
    // null for a delivery with no Deliver by time and for a cancelled one.
    public function getTimingAttribute(): ?array
    {
        if (! $this->due_at || $this->status === 'cancelled') {
            return null;
        }
        if ($this->status === 'delivered' && $this->delivered_at) {
            $late = $this->delivered_at->gt($this->due_at);

            return ['state' => $late ? 'late' : 'on_time', 'minutes' => $late ? (int) $this->due_at->diffInMinutes($this->delivered_at) : 0];
        }
        $now = now();

        return $now->gt($this->due_at)
            ? ['state' => 'overdue', 'minutes' => (int) $this->due_at->diffInMinutes($now)]
            : ['state' => 'due', 'minutes' => (int) $now->diffInMinutes($this->due_at)];
    }

    // Earliest Deliver by first; deliveries without one after them; then the
    // oldest order (FIFO), then the order they were made in.
    public function scopeByDeadline($query)
    {
        return $query->orderByRaw('due_at is null')->orderBy('due_at')->orderBy('ordered_at')->orderBy('id');
    }

    public function driver()
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function reassignedFrom()
    {
        return $this->belongsTo(User::class, 'reassigned_from_driver_id');
    }

    public function contract()
    {
        return $this->belongsTo(DeliveryContract::class, 'contract_id');
    }

    public function notifications()
    {
        return $this->hasMany(DeliveryNotification::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    } // ✅ fixed

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function preparedBy()
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function items()
    {
        return $this->hasMany(DeliveryItem::class);
    }

    public function proofs()
    {
        return $this->hasMany(DeliveryProof::class);
    }

    // The Driver and the Salesman pressing Arrived at the store (GPS checked).
    public function arrivals()
    {
        return $this->hasMany(DeliveryArrival::class);
    }

    // An urgent top-up points at the delivery it followed (App\Support\DeliveryAdjust).
    public function parent()
    {
        return $this->belongsTo(Delivery::class, 'parent_delivery_id');
    }

    public function topUps()
    {
        return $this->hasMany(Delivery::class, 'parent_delivery_id');
    }

    // Top-ups and add/reduce sacks changes, newest first.
    public function adjustments()
    {
        return $this->hasMany(DeliveryAdjustment::class)->latest();
    }

    // the store's own requests to change the sacks (newest first)
    public function adjustmentRequests()
    {
        return $this->hasMany(DeliveryAdjustmentRequest::class)->latest();
    }
}
