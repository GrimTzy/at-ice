<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// A person has at most one OPEN attendance record (no Time Out) per business date.
//
// Business key: (face_data_id, date) among rows where time_out IS NULL.
//  - One face per employee (FaceDataController), so face_data_id is the person.
//  - Closed records (time_out set) never count, so a Time Out followed by the
//    next shift's Time In is untouched.
//  - Open records of OTHER dates stay allowed: a forgotten Time Out is a
//    legitimate state (App\Support\MemoEngine writes a memo for it, and
//    AttendanceController treats a record older than MAX_SHIFT_HOURS as a missed
//    Time Out, not an open shift), so "one open record per person" overall would
//    block the next day's Time In.
//  - Rows without a face_data_id (legacy or manual) are not constrained.
//
// MySQL and SQLite cannot express "unique where time_out IS NULL" directly, so a
// generated column holds "<face_data_id>#<date>" while the record is open and
// NULL once it is closed (NULLs never collide in a unique index). The column is
// computed by the database, so no code has to keep it in step.
//
// This migration does NOT delete or merge anything. If duplicates already exist
// it stops with the detection query and the steps to take by hand. Run
// database/sql/attendance-duplicates.sql on the target database before deploying.
return new class extends Migration
{
    private const DETECTION_SQL = 'SELECT face_data_id, date, COUNT(*) AS open_rows FROM attendances WHERE time_out IS NULL AND face_data_id IS NOT NULL GROUP BY face_data_id, date HAVING COUNT(*) > 1';

    private const INDEX = 'attendances_one_open_per_person_day_unique';

    public function up(): void
    {
        $duplicates = DB::table('attendances')
            ->select('face_data_id', 'date', DB::raw('COUNT(*) AS open_rows'))
            ->whereNull('time_out')
            ->whereNotNull('face_data_id')
            ->groupBy('face_data_id', 'date')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicates->isNotEmpty()) {
            $sample = $duplicates->take(5)->map(fn ($d) => "face_data_id {$d->face_data_id}, date ".substr((string) $d->date, 0, 10).": {$d->open_rows} open rows")->implode('; ');

            throw new RuntimeException(
                "Cannot add the one-open-attendance-per-person-and-day rule: {$duplicates->count()} person/day pair(s) already have more than one open attendance row ({$sample}).\n"
                .'Nothing was changed. Find them with: '.self::DETECTION_SQL.";\n"
                ."Then, by hand and with HR: for each pair keep the earliest row (the real Time In), check whether the others have employee memos (employee_memos.attendance_id) or payroll effects, remove the extra rows, and run the migration again.\n"
                .'Back up the database before deleting anything.'
            );
        }

        match (DB::getDriverName()) {
            'mysql', 'mariadb' => DB::unprepared(
                'ALTER TABLE attendances '
                .'ADD COLUMN open_key VARCHAR(40) GENERATED ALWAYS AS (CASE WHEN time_out IS NULL AND face_data_id IS NOT NULL THEN CONCAT(face_data_id, \'#\', date) ELSE NULL END) VIRTUAL, '
                .'ADD UNIQUE INDEX '.self::INDEX.' (open_key)'
            ),
            'sqlite' => $this->sqliteUp(),
            default => throw new RuntimeException('The attendance rule needs MySQL or SQLite (generated column).'),
        };
    }

    public function down(): void
    {
        match (DB::getDriverName()) {
            'mysql', 'mariadb' => DB::unprepared('ALTER TABLE attendances DROP INDEX '.self::INDEX.', DROP COLUMN open_key'),
            'sqlite' => $this->sqliteDown(),
            default => null,
        };
    }

    private function sqliteUp(): void
    {
        DB::unprepared("ALTER TABLE attendances ADD COLUMN open_key TEXT GENERATED ALWAYS AS (CASE WHEN time_out IS NULL AND face_data_id IS NOT NULL THEN face_data_id || '#' || substr(date, 1, 10) ELSE NULL END) VIRTUAL");
        DB::unprepared('CREATE UNIQUE INDEX '.self::INDEX.' ON attendances (open_key)');
    }

    private function sqliteDown(): void
    {
        DB::unprepared('DROP INDEX IF EXISTS '.self::INDEX);
        DB::unprepared('ALTER TABLE attendances DROP COLUMN open_key');
    }
};
