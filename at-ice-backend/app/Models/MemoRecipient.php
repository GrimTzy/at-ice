<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// One employee who received an announcement (HR > Memos), and when they read
// and acknowledged it.
class MemoRecipient extends Model
{
    protected $fillable = ['memo_id', 'employee_id', 'read_at', 'acknowledged_at'];

    protected $casts = [
        'read_at' => 'datetime',
        'acknowledged_at' => 'datetime',
    ];

    public function memo()
    {
        return $this->belongsTo(EmployeeMemo::class, 'memo_id');
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
