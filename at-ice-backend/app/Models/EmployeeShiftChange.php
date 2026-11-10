<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// One change of an employee's shift hours (HR > Employees).
class EmployeeShiftChange extends Model
{
    protected $fillable = [
        'employee_id', 'from_start', 'from_end', 'to_start', 'to_end',
        'effective_date', 'changed_by', 'reason',
    ];

    protected $casts = [
        'effective_date' => 'date:Y-m-d',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function changedBy()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
