<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// A project on Admin's board (Admin > Projects): a maintenance or plant job
// that uses inventory supplies through Maintenance stock-out requests.
class Project extends Model
{
    public const STATUSES = ['planned', 'working', 'stuck', 'done'];

    public const PRIORITIES = ['low', 'medium', 'high', 'critical'];

    protected $fillable = [
        'name', 'description', 'location', 'latitude', 'longitude', 'geofence_radius_m', 'status', 'priority', 'owner',
        'start_date', 'due_date', 'budget', 'created_by',
    ];

    protected $casts = [
        'start_date' => 'date:Y-m-d',
        'due_date' => 'date:Y-m-d',
        'budget' => 'decimal:2',
    ];

    public function stockOutRequests()
    {
        return $this->hasMany(StockOutRequest::class);
    }

    // Schedule progress, not work progress: the share of the time from start to
    // due date that has passed (done = 100). Null without both dates.
    public function scheduleProgress(): ?int
    {
        if ($this->status === 'done') {
            return 100;
        }
        if (! $this->start_date || ! $this->due_date) {
            return null;
        }
        $span = max(1, $this->start_date->diffInDays($this->due_date));

        return (int) max(0, min(100, round($this->start_date->diffInDays(now(config('atice.timezone')), false) / $span * 100)));
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
