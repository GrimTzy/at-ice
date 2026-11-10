<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Admin's changes to an employee's appraisal for a pay period (final rating,
// goal, remarks, published). The draft rating itself is App\Support\PerformanceScore.
class PerformanceReview extends Model
{
    protected $fillable = [
        'employee_id', 'period_start', 'period_end', 'final_rating', 'goal_target', 'remarks',
        'status', 'updated_by', 'published_by', 'published_at',
        'published_rating', 'snapshot', 'snapshot_at',
    ];

    protected $casts = [
        'period_start' => 'date:Y-m-d',
        'period_end' => 'date:Y-m-d',
        'final_rating' => 'float',
        'published_rating' => 'float',
        'snapshot' => 'array',
        'snapshot_at' => 'datetime',
        'goal_target' => 'integer',
        'published_at' => 'datetime',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function publisher()
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    // The numbers a published rating was worked out from (kept in `snapshot`).
    public const SNAPSHOT_KEYS = [
        'role', 'work_days', 'present_days', 'on_time_days', 'attendance_rate', 'punctuality_rate',
        'goal_target', 'goal_done', 'goal_completion', 'score', 'base_rating', 'final_rating', 'rating', 'rating_label',
    ];
}
