<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Delivery extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'delivery_no', 'contract_id', 'driver_id', 'reassigned_from_driver_id', 'customer_id', 'delivery_type',
        'supplier_id', 'status', 'scheduled_date', 'ordered_at', 'due_at', 'due_from',
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
        'due_from' => 'datetime',
        'dispatched_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    protected $appends = ['timing'];

    // Against its time window (due_from to due_at; due_at, Deliver by, is the
    // deadline): delivered at or before the deadline is on_time, after it is
    // late, and before the window opens is early; not delivered yet and past
    // the deadline is overdue, otherwise due. minutes: how late or early, or
    // how long until it is due. null with no Deliver by time and when cancelled.
    public function getTimingAttribute(): ?array
    {
        if (! $this->due_at || $this->status === 'cancelled') {
            return null;
        }
        if ($this->status === 'delivered' && $this->delivered_at) {
            $late = $this->delivered_at->gt($this->due_at);
            if (! $late && $this->due_from && $this->delivered_at->lt($this->due_from)) {
                return ['state' => 'early', 'minutes' => (int) $this->delivered_at->diffInMinutes($this->due_from)];
            }

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

    // The Checker's truck counts (Ice loading), one row per product
    public function iceLoadings()
    {
        return $this->hasMany(IceLoading::class);
    }

    // The Driver and the Salesman pressing Arrived at the store (GPS checked).
    public function arrivals()
    {
        return $this->hasMany(DeliveryArrival::class);
    }

    // Add/reduce sacks changes, newest first.
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
