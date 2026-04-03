<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Attendance extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'recorded_by',
        'date',
        'time_in',
        'time_out',
        'hours_worked',
        'status',
        'notes',
    ];

    protected $casts = [
        'date' => 'date',
        'hours_worked' => 'decimal:2',
    ];

    // Relationship — Attendance belongs to Employee
    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    // Relationship — Attendance recorded by User
    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}