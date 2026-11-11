<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LeaveRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'leave_type',
        'start_date',
        'end_date',
        'total_days',
        'is_half_day',
        'reason',
        'status',
        'approved_by',
        'approved_at',
        'admin_notes',
        'attachment_path',
        'attachment_disk',
        'hours',
        'is_paid',
        'paid_days',
        'unpaid_days',
        'reject_reason',
    ];

    // date:Y-m-d so start/end dates are sent as plain "2026-10-01"
    // (no timezone shift when the frontend reads them)
    protected $casts = [
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
        'approved_at' => 'datetime',
        'is_half_day' => 'boolean',
        'is_paid' => 'boolean',
        'hours' => 'decimal:2',
        'paid_days' => 'decimal:2',
        'unpaid_days' => 'decimal:2',
    ];

    // Labels used in the rules and messages
    public const TYPE_LABELS = [
        'vacation' => 'Vacation leave (VL)',
        'sick' => 'Sick leave (SL)',
        'emergency' => 'Emergency leave (EL)',
        'maternity' => 'Maternity leave',
        'paternity' => 'Paternity leave',
        'solo_parent' => 'Solo parent leave',
        'vawc' => 'VAWC leave',
        'special_women' => 'Special leave for women',
    ];

    // Company leave and the credits it uses (QA review, 2026-11-11: 15 VL and
    // 15 SL a year once regular). Emergency leave comes out of the VL credits.
    public const POOL = ['vacation' => 'vacation', 'emergency' => 'vacation', 'sick' => 'sick'];

    // Leave the law gives, apart from the credits and paid in full (PH law):
    //   days         most days in one request
    //   calendar     counted in calendar days (otherwise work days)
    //   sex          only for that sex (biodata)
    //   service      months of service needed first
    //   per_year     most days in a calendar year
    //   proof        what HR checks (the attachment is required)
    public const STATUTORY = [
        // RA 11210: 105 days (120 for a solo parent; 60 after a miscarriage)
        'maternity' => ['days' => 120, 'calendar' => true, 'sex' => 'female', 'service' => 0, 'per_year' => null,
            'law' => 'RA 11210', 'proof' => 'Medical certificate or ultrasound showing the due date'],
        // RA 8187: 7 work days for a married male employee, first four deliveries
        'paternity' => ['days' => 7, 'calendar' => false, 'sex' => 'male', 'service' => 0, 'per_year' => null,
            'law' => 'RA 8187', 'proof' => 'Marriage contract and the child\'s birth certificate or proof of the delivery'],
        // RA 11861: 7 work days a year after 6 months of service
        'solo_parent' => ['days' => 7, 'calendar' => false, 'sex' => null, 'service' => 6, 'per_year' => 7,
            'law' => 'RA 11861', 'proof' => 'Solo Parent ID'],
        // RA 9262: up to 10 days
        'vawc' => ['days' => 10, 'calendar' => false, 'sex' => 'female', 'service' => 0, 'per_year' => 10,
            'law' => 'RA 9262', 'proof' => 'Barangay or court protection order, or a police or prosecutor\'s certification'],
        // RA 9710: up to 2 months after surgery for a gynecological disorder, 6 months of service
        'special_women' => ['days' => 60, 'calendar' => true, 'sex' => 'female', 'service' => 6, 'per_year' => 60,
            'law' => 'RA 9710', 'proof' => 'Medical certificate of the gynecological surgery'],
    ];

    public static function isStatutory(string $type): bool
    {
        return isset(self::STATUTORY[$type]);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
