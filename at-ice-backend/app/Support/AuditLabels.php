<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Plain words for the audit log (Admin > Settings > Recent changes): what was
// done and to what. Unknown actions fall back to the action name in words.
class AuditLabels
{
    private const ACTIONS = [
        'employee.updated' => 'Edited employee details',
        'employee.shift_changed' => 'Changed work schedule',
        'employee.regularized' => 'Made employee regular',
        'employee.deactivated' => 'Deactivated employee',
        'employee.reactivated' => 'Reactivated employee',
        'employee_document.added' => 'Added employee document',
        'employee_document.removed' => 'Removed employee document',
        'leave.approved' => 'Approved leave',
        'leave.rejected' => 'Rejected leave',
        'memo.create' => 'Drafted memo',
        'memo.update' => 'Edited memo',
        'memo.send' => 'Released memo to employee',
        'memo.cancel' => 'Cancelled memo',
        'memo.signed' => 'Uploaded signed memo',
        'memo.attach' => 'Attached file to memo',
        'memo.acknowledge' => 'Employee acknowledged memo',
        'memo.investigation_closed' => 'Closed investigation',
        'memo.endorsed' => 'Endorsed memo to Admin',
        'memo.approved' => 'Approved memo',
        'memo.returned' => 'Returned memo to HR',
        'memo.announcement_sent' => 'Sent announcement',
        'holiday.added' => 'Added holiday',
        'holiday.changed' => 'Changed holiday',
        'holiday.removed' => 'Removed holiday',
        'support.update' => 'Updated support ticket',
        'support.resolve' => 'Resolved support ticket',
        'support.close' => 'Closed support ticket',
        'support.reopen' => 'Reopened support ticket',
        'support.escalated' => 'Escalated support ticket',
        'support.de_escalated' => 'Removed escalation',
        'payroll.paid' => 'Marked payroll paid',
        'payroll.adjustment_added' => 'Added payroll adjustment',
        'payroll.adjustment_removed' => 'Removed payroll adjustment',
        'timesheet.approved' => 'Approved timesheet',
        'overtime.approved' => 'Approved overtime',
        'setting.changed' => 'Changed a setting',
        'pay_multiplier.changed' => 'Changed a pay rate',
        'kiosk.pin_changed' => 'Changed kiosk PIN',
        'kiosk.admin_pin_changed' => 'Changed Admin PIN',
        'kiosk.hr_override_pin_changed' => 'Changed HR override PIN',
        'kiosk.override' => 'Unlocked kiosk',
        'kiosk.override_failed' => 'Failed kiosk unlock',
        'kiosk.locked' => 'Kiosk locked',
        'kiosk.ip_blocked' => 'Kiosk blocked an address',
    ];

    private const ROLES = [
        'admin' => 'Admin', 'hr' => 'HR', 'customer' => 'Customer', 'driver' => 'Driver', 'employee' => 'Employee',
    ];

    public static function action(?string $action): string
    {
        $action = (string) $action;

        return self::ACTIONS[$action] ?? Str::ucfirst(str_replace(['.', '_'], ' ', $action));
    }

    public static function role(?string $role, ?string $subRole = null): ?string
    {
        if ($role === 'employee' && $subRole) {
            return Str::ucfirst($subRole);
        }

        return $role ? (self::ROLES[$role] ?? Str::ucfirst($role)) : null;
    }

    // "Juan Dela Cruz (EMP-0001)", "Memo MEMO-2026-0004", "Ticket #12"...
    // One query per subject type for the whole page of logs.
    public static function subjects(Collection $logs): array
    {
        $labels = [];
        foreach ($logs->whereNotNull('subject_type')->groupBy('subject_type') as $type => $rows) {
            $ids = $rows->pluck('subject_id')->filter()->unique()->values();
            foreach (self::lookup($type, $ids) as $id => $label) {
                $labels[$type.':'.$id] = $label;
            }
        }

        return $labels;
    }

    private static function lookup(string $type, Collection $ids): array
    {
        if ($ids->isEmpty()) {
            return [];
        }

        return match ($type) {
            'Employee' => DB::table('employees')->whereIn('id', $ids)->get(['id', 'first_name', 'last_name', 'employee_id'])
                ->mapWithKeys(fn ($e) => [$e->id => trim("{$e->first_name} {$e->last_name}")." ({$e->employee_id})"])->all(),
            'EmployeeMemo' => DB::table('employee_memos')->whereIn('id', $ids)->pluck('memo_no', 'id')
                ->map(fn ($no, $id) => 'Memo '.($no ?: "#{$id}"))->all(),
            'SupportMessage' => DB::table('support_messages')->whereIn('id', $ids)->pluck('id', 'id')
                ->map(fn ($id) => "Ticket #{$id}")->all(),
            'LeaveRequest' => DB::table('leave_requests')->join('employees', 'employees.id', '=', 'leave_requests.employee_id')
                ->whereIn('leave_requests.id', $ids)->get(['leave_requests.id', 'employees.first_name', 'employees.last_name'])
                ->mapWithKeys(fn ($l) => [$l->id => 'Leave of '.trim("{$l->first_name} {$l->last_name}")])->all(),
            default => $ids->mapWithKeys(fn ($id) => [$id => Str::headline($type)." #{$id}"])->all(),
        };
    }
}
