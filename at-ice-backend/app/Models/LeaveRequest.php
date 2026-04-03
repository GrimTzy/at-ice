<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class LeaveRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'leave_type',
        'start_date',
        'end_date',
        'total_days',
        'reason',
        'status',
        'approved_by',
        'approved_at',
        'admin_notes',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'approved_at' => 'datetime',
    ];

    // Relationship — LeaveRequest belongs to Employee
    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    // Relationship — LeaveRequest approved by User
    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}