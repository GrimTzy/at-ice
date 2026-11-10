<?php

namespace App\Models;

use App\Support\DriverAvailability;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Delivery extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'delivery_no', 'contract_id', 'driver_id', 'reassigned_from_driver_id', 'customer_id', 'delivery_type',
        'supplier_id', 'status', 'scheduled_date', 'ordered_at', 'due_at',
        'dispatched_at', 'delivered_at', 'notes', 'cancel_reason', 'prepared_by',
        'parent_delivery_id', 'is_top_up', 'route_id', 'rescheduled_from',
    ];

    // pending: waiting for its Driver to start it; queued (shown as "Waiting"):
    // it has no Driver. A route stop waits there when its Driver is absent and
    // the reserve driver cannot take it (Admin assigns a Driver or reschedules
    // it); any other delivery waits (earliest Deliver by first, then FIFO:
    // oldest ordered_at) for the Driver who finished their list first;
    // in_transit; delivered; cancelled.
    public const STATUSES = ['pending', 'queued', 'in_transit', 'delivered', 'cancelled'];

    protected $casts = [
        'is_top_up' => 'boolean',
        'scheduled_date' => 'date:Y-m-d',
        'rescheduled_from' => 'date:Y-m-d',
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

    // The stored date (Y-m-d), read raw so the app timezone cannot shift it.
    public function scheduledDateString(): ?string
    {
        $raw = $this->getRawOriginal('scheduled_date') ?? $this->scheduled_date;

        return $raw ? substr((string) ($raw instanceof \DateTimeInterface ? $raw->format('Y-m-d') : $raw), 0, 10) : null;
    }

    // Scheduled after today (business timezone): it cannot be started, arrived
    // at or confirmed yet. Overdue deliveries (earlier days) still can.
    public function isForLaterDay(): bool
    {
        $date = $this->scheduledDateString();

        return $date !== null && $date > DriverAvailability::today();
    }

    public function laterDayMessage(string $action): string
    {
        $date = Carbon::parse($this->scheduledDateString())->format('M j, Y');

        return "{$this->delivery_no} is scheduled for {$date}. It can be {$action} on that day, not before.";
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

    public function route()
    {
        return $this->belongsTo(DeliveryRoute::class, 'route_id');
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
