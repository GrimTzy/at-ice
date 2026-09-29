<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Employee extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'employee_id',
        'first_name',
        'last_name',
        'phone',
        'address',
        'position',
        'department',
        'daily_rate',
        'date_hired',
        'employment_type',
        'shift',
        'vehicle_plate',
        'is_active',
    ];

    protected $casts = [
        'date_hired' => 'date',
        'is_active' => 'boolean',
        'daily_rate' => 'decimal:2',
    ];

    // Relationship - Employee belongs to User
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Relationship - Employee has many Attendances
    public function attendances()
    {
        return $this->hasMany(Attendance::class);
    }

    // Relationship - Employee has many Leave Requests
    public function leaveRequests()
    {
        return $this->hasMany(LeaveRequest::class);
    }

    // Relationship - Employee has many Payrolls
    public function payrolls()
    {
        return $this->hasMany(Payroll::class);
    }

    // Full name accessor
    public function getFullNameAttribute()
    {
        return $this->first_name . ' ' . $this->last_name;
    }
}