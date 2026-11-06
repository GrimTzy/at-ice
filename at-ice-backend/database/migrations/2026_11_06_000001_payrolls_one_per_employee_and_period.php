<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// One payroll per employee and per pay period.
//
// Business key: (employee_id, period_start). A pay period is identified by its
// start date (App\Support\PayPeriod::for()), and App\Http\Controllers\
// TimesheetController::approve is the only writer: it finds the payroll with
// exactly this pair and redoes it, so a second row for the pair is never
// intended. Until now nothing enforced it, and parallel approvals made one row
// per request.
//
// This migration does NOT delete or merge anything. If duplicates already exist
// it stops with the detection query and the steps to take by hand, so nothing
// is chosen for the owner. Run database/sql/payroll-duplicates.sql on the target
// database before deploying.
return new class extends Migration
{
    private const DETECTION_SQL = 'SELECT employee_id, period_start, COUNT(*) AS payroll_rows FROM payrolls GROUP BY employee_id, period_start HAVING COUNT(*) > 1';

    public function up(): void
    {
        $duplicates = DB::table('payrolls')
            ->select('employee_id', 'period_start', DB::raw('COUNT(*) AS payroll_rows'))
            ->groupBy('employee_id', 'period_start')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicates->isNotEmpty()) {
            $sample = $duplicates->take(5)->map(fn ($d) => "employee_id {$d->employee_id}, period_start {$d->period_start}: {$d->payroll_rows} rows")->implode('; ');

            throw new RuntimeException(
                "Cannot add the one-payroll-per-employee-and-period rule: {$duplicates->count()} employee/period pair(s) already have more than one payroll row ({$sample}).\n"
                .'Nothing was changed. Find them with: '.self::DETECTION_SQL.";\n"
                ."Then, by hand and with HR: for each pair keep the row that was paid (or the most recent approval if none is paid), move or re-enter its adjustments (payroll_adjustments.payroll_id), delete the other rows, and run the migration again.\n"
                .'Back up the database before deleting anything.'
            );
        }

        Schema::table('payrolls', function (Blueprint $table) {
            $table->unique(['employee_id', 'period_start'], 'payrolls_employee_period_unique');
        });
    }

    public function down(): void
    {
        // On MySQL the foreign key on employee_id uses the unique index (employee_id
        // is its first column), so it cannot be dropped until a plain index on
        // employee_id exists for the foreign key to fall back on (error 1553).
        Schema::table('payrolls', function (Blueprint $table) {
            $table->index('employee_id', 'payrolls_employee_id_index');
        });
        Schema::table('payrolls', function (Blueprint $table) {
            $table->dropUnique('payrolls_employee_period_unique');
        });
    }
};
