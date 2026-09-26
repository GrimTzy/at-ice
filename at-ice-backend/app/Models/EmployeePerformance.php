<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeePerformance extends Model
{
    protected $table = 'employee_performance';

    protected $fillable = [
        'employee_id',
        'review_period_start',
        'review_period_end',
        'rating',
        'notes',
        'reviewed_by',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}