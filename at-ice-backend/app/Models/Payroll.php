<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Payroll extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'period_start',
        'period_end',
        'days_worked',
        'daily_rate',
        'gross_pay',
        'sss_deduction',
        'philhealth_deduction',
        'pagibig_deduction',
        'tax_deduction',
        'other_deductions',
        'net_pay',
        'status',
        'prepared_by',
        'paid_at',
        'notes',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'paid_at' => 'datetime',
        'daily_rate' => 'decimal:2',
        'gross_pay' => 'decimal:2',
        'sss_deduction' => 'decimal:2',
        'philhealth_deduction' => 'decimal:2',
        'pagibig_deduction' => 'decimal:2',
        'tax_deduction' => 'decimal:2',
        'other_deductions' => 'decimal:2',
        'net_pay' => 'decimal:2',
    ];

    // Relationship — Payroll belongs to Employee
    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    // Relationship — Payroll prepared by User
    public function preparedBy()
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }
}