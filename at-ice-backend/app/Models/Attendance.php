<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Attendance extends Model
{
    use HasFactory;

    protected $fillable = [
        'face_data_id',
        'employee_id',
        'recorded_by',
        'date',
        'time_in',
        'time_out',
        'hours_worked',
        'late_minutes',
        'ot_minutes',
        'ot_approved',
        'status',
        'notes',
    ];

    // open_key is computed by the database (one open record per person and day,
    // migration 2026_11_06_000002); it is not part of the API.
    protected $hidden = ['open_key'];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'hours_worked' => 'decimal:2',
        'ot_approved' => 'boolean',
    ];

    // Relationship - Attendance belongs to FaceData
    public function faceData()
    {
        return $this->belongsTo(FaceData::class);
    }

    // Relationship - Attendance belongs to Employee
    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    // Relationship - Attendance recorded by User
    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
