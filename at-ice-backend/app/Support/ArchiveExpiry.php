<?php

namespace App\Support;

use App\Models\Employee;
use App\Models\FaceData;
use App\Models\LeaveRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

// Deactivated employees, in two steps (QA review, 2026-11-11):
// 1. retention.personal_purge_days (30) after deactivation, the personal data is
//    deleted for data privacy: login, face, uploaded documents and leave
//    attachments, phone, address, emergency contact and biodata. What the Labor
//    Code makes the employer keep stays: name, employee ID, position, dates, pay
//    rates, government numbers, attendance, leave and payroll.
// 2. Past the retention period (retention.years, 3: the DOLE minimum for
//    employment and payroll records) archive.expiry_action decides:
//      ANONYMIZE  the rest of the personal details removed and the employee code
//                 freed for a new hire; dates and pay history kept (the default)
//      REVIEW     nothing on its own; Admin decides in Settings
//      PURGE      the employee and their records deleted
// Every action is audited. The scheduler runs it once a day.
class ArchiveExpiry
{
    public static function cutoff(): string
    {
        return now(config('atice.timezone'))->subYears((int) Settings::get('retention.years'))->toDateString();
    }

    // Inactive past the retention period, not kept by Admin, not anonymized yet.
    public static function expired()
    {
        $held = DB::table('retention_holds')->where('kind', 'employee')
            ->whereDate('kept_until', '>=', now(config('atice.timezone'))->toDateString())->pluck('ref')->all();

        return Employee::where('is_active', false)
            ->whereNotNull('deactivated_at')
            ->whereNull('anonymized_at')
            ->whereDate('deactivated_at', '<=', self::cutoff())
            ->whereNotIn('id', $held);
    }

    // Deactivated long enough ago for step 1, and not done yet.
    public static function dueForPersonalPurge()
    {
        $before = now(config('atice.timezone'))->subDays((int) Settings::get('retention.personal_purge_days'))->toDateString();

        return Employee::where('is_active', false)
            ->whereNotNull('deactivated_at')
            ->whereNull('personal_purged_at')
            ->whereNull('anonymized_at')
            ->whereDate('deactivated_at', '<=', $before);
    }

    // Once a day, from the scheduler.
    public static function daily(): void
    {
        try {
            if (! Cache::add('archive-expiry:'.now(config('atice.timezone'))->toDateString(), true, now()->addDays(2))) {
                return;
            }
            foreach (self::dueForPersonalPurge()->get() as $employee) {
                self::purgePersonal($employee);
            }
            self::run();
        } catch (Throwable $e) {
            Log::warning('ArchiveExpiry: '.$e->getMessage());
        }
    }

    // Applies archive.expiry_action to every expired employee; returns how many.
    public static function run(): int
    {
        $action = Settings::get('archive.expiry_action');
        if ($action === 'REVIEW') {
            return 0;
        }
        $count = 0;
        foreach (self::expired()->get() as $employee) {
            $action === 'PURGE' ? self::purge($employee, true) : self::anonymize($employee, true);
            $count++;
        }

        return $count;
    }

    // Step 1: the personal data goes, the records DOLE needs stay.
    public static function purgePersonal(Employee $employee): void
    {
        DB::transaction(function () use ($employee) {
            FaceData::where('employee_id', $employee->id)->delete();
            foreach (LeaveRequest::where('employee_id', $employee->id)->whereNotNull('attachment_path')->get() as $leave) {
                StoredFiles::deleteAfterCommit($leave->attachment_disk ?: 'public', $leave->attachment_path);
                $leave->forceFill(['attachment_path' => null])->save();
            }
            if ($employee->user) {
                $employee->user->tokens()->delete();
                $employee->user->forceFill([
                    'email' => "removed-{$employee->user->id}@atice.invalid",
                    'password' => bcrypt(Str::random(40)),
                    'is_active' => false,
                ])->save();
            }
            $employee->forceFill([
                'phone' => null,
                'address' => null,
                'block_lot' => null,
                'street' => null,
                'subdivision' => null,
                'barangay' => null,
                'city' => null,
                'province' => null,
                'region' => null,
                'barangay_code' => null,
                'postal_code' => null,
                'vehicle_plate' => null,
                'license_number' => null,
                'license_expiry' => null,
                'personal_purged_at' => now(),
            ] + array_fill_keys(\App\Support\Biodata::FIELDS, null))->save();
            foreach ($employee->documents()->get() as $doc) {
                $doc->delete();
                StoredFiles::deleteAfterCommit('local', $doc->path);
            }
        });
        Audit::log('retention.personal_purged', $employee, ['employee' => "{$employee->employee_id} {$employee->full_name}"], ['auto' => true]);
    }

    public static function anonymize(Employee $employee, bool $auto = false): void
    {
        $label = "{$employee->employee_id} {$employee->full_name}";
        DB::transaction(function () use ($employee) {
            FaceData::where('employee_id', $employee->id)->delete();
            foreach (LeaveRequest::where('employee_id', $employee->id)->whereNotNull('attachment_path')->get() as $leave) {
                StoredFiles::deleteAfterCommit($leave->attachment_disk ?: 'public', $leave->attachment_path);
                $leave->forceFill(['attachment_path' => null])->save();
            }
            if ($employee->user) {
                $employee->user->tokens()->delete();
                $employee->user->forceFill([
                    'name' => 'Former employee',
                    'email' => "anonymized-{$employee->user->id}@atice.invalid",
                    'password' => bcrypt(Str::random(40)),
                ])->save();
            }
            $employee->forceFill([
                'first_name' => 'Former',
                'middle_initial' => null,
                'last_name' => 'Employee',
                'suffix' => null,
                'phone' => null,
                'address' => null,
                'block_lot' => null,
                'street' => null,
                'subdivision' => null,
                'barangay' => null,
                'city' => null,
                'province' => null,
                'region' => null,
                'barangay_code' => null,
                'postal_code' => null,
                'vehicle_plate' => null,
                'license_number' => null,
                'license_expiry' => null,
                // the code is freed for a new hire (QA review, 2026-11-11)
                'employee_id' => 'FMR-'.str_pad((string) $employee->id, 5, '0', STR_PAD_LEFT),
                'anonymized_at' => now(),
            ] + array_fill_keys(array_merge(\App\Support\Biodata::FIELDS, \App\Support\Biodata::GOV_FIELDS), null))->save();
            // attached biodata files go too (private disk)
            foreach ($employee->documents()->get() as $doc) {
                $doc->delete();
                StoredFiles::deleteAfterCommit('local', $doc->path); // only once the transaction has committed
            }
        });
        Audit::log('retention.anonymized', $employee, ['employee' => $label], ['auto' => $auto]);
    }

    public static function purge(Employee $employee, bool $auto = false): void
    {
        $label = "{$employee->employee_id} {$employee->full_name}";
        DB::transaction(function () use ($employee) {
            FaceData::where('employee_id', $employee->id)->delete();
            // Attendance, leave, payroll and document rows go with it (foreign keys cascade); the
            // files go only once the whole transaction has committed.
            $paths = $employee->documents()->pluck('path')->all();
            $employee->delete();
            StoredFiles::deleteAfterCommit('local', $paths);
        });
        Audit::log('retention.deleted', null, ['employee' => $label], ['auto' => $auto]);
    }
}
