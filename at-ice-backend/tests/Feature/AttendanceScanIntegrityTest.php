<?php

namespace Tests\Feature;

use App\Events\AttendanceRecorded;
use App\Http\Middleware\EnsureKioskToken;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\EmployeeMemo;
use App\Models\FaceData;
use App\Support\MemoEngine;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

// Simultaneous Time In scans of one person made
// several open attendance rows. Rule: a person has at most one OPEN record
// (no Time Out) per business date. Older open records from other dates are a
// forgotten Time Out (App\Support\MemoEngine writes a memo for each), so they
// stay allowed next to today's record. Closed records never count. These tests
// run on SQLite; ConcurrentWritesMysqlTest runs the real parallel scans on MySQL.
class AttendanceScanIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_11_06_000002_attendances_one_open_per_person_and_day.php';

    private Employee $employee;

    private FaceData $face;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnsureKioskToken::class);
        $this->employee = $this->makeEmployee('EMP-0001', '09:00', '18:00', 'day');
        $this->face = $this->enroll($this->employee);
    }

    private function makeEmployee(string $code, string $start, string $end, string $shift): Employee
    {
        return Employee::create([
            'employee_id' => $code, 'first_name' => 'Ana', 'last_name' => $code, 'position' => 'Guard', 'daily_rate' => 700,
            'date_hired' => '2026-01-05', 'employment_type' => 'regular', 'shift' => $shift, 'shift_start' => $start, 'shift_end' => $end,
            'is_active' => true, 'employment_status' => 'regular', 'regularized_at' => '2026-01-05',
        ]);
    }

    private function enroll(Employee $e): FaceData
    {
        return FaceData::create(['worker_name' => $e->first_name.' '.$e->last_name, 'role' => 'guard', 'employee_id' => $e->id, 'descriptor' => array_fill(0, 128, 0.1)]);
    }

    private function at(int $month, int $day, int $hour, int $minute = 0): void
    {
        $this->travelTo(now('Asia/Manila')->setDate(2026, $month, $day)->setTime($hour, $minute));
    }

    private function scan(string $type, ?FaceData $face = null)
    {
        return $this->postJson('/api/attendances', ['face_data_id' => ($face ?? $this->face)->id, 'type' => $type]);
    }

    private function openRow(array $o = []): Attendance
    {
        return Attendance::create($o + [
            'face_data_id' => $this->face->id, 'employee_id' => $this->employee->id, 'date' => '2026-10-20',
            'time_in' => '09:00:00', 'time_out' => null, 'hours_worked' => 0, 'status' => 'present',
        ]);
    }

    // ---- the rule at the database level --------------------------------------

    public function test_the_database_allows_only_one_open_attendance_per_person_and_day(): void
    {
        $this->openRow();

        $this->expectException(UniqueConstraintViolationException::class);
        $this->openRow(['time_in' => '09:00:07']);
    }

    public function test_the_database_allows_open_records_on_different_days_and_for_different_people(): void
    {
        $this->openRow(['date' => '2026-10-19']); // a forgotten Time Out
        $this->openRow(['date' => '2026-10-20']);
        $other = $this->enroll($this->makeEmployee('EMP-0002', '09:00', '18:00', 'day'));
        $this->openRow(['face_data_id' => $other->id, 'employee_id' => $other->employee_id, 'date' => '2026-10-20']);

        $this->assertSame(3, Attendance::whereNull('time_out')->count());
    }

    public function test_a_closed_record_does_not_count_toward_the_limit(): void
    {
        $this->openRow(['time_out' => '17:00:00', 'hours_worked' => 8]);
        $this->openRow(['time_in' => '18:30:00']);

        $this->assertSame(2, Attendance::count());
    }

    // ---- legitimate flows must keep working ----------------------------------

    public function test_time_in_time_out_and_the_next_days_time_in_all_work(): void
    {
        $this->at(10, 20, 9);
        $this->scan('time_in')->assertCreated();
        $this->at(10, 20, 18);
        $this->scan('time_out')->assertOk();
        $this->at(10, 21, 9);
        $this->scan('time_in')->assertCreated();

        $this->assertSame(2, Attendance::count());
        $this->assertSame(1, Attendance::whereNull('time_out')->count());
    }

    public function test_a_forgotten_time_out_yesterday_does_not_block_todays_time_in(): void
    {
        $this->at(10, 20, 9);
        $this->scan('time_in')->assertCreated();
        $this->at(10, 21, 9); // more than 16 hours later: yesterday's record is a missed Time Out
        $this->scan('time_in')->assertCreated();

        $this->assertSame(2, Attendance::whereNull('time_out')->count());
    }

    public function test_a_night_shift_can_time_out_the_next_morning_and_time_in_again_that_night(): void
    {
        $night = $this->makeEmployee('EMP-0003', '22:00', '06:00', 'night');
        $face = $this->enroll($night);

        $this->at(10, 20, 22);
        $this->scan('time_in', $face)->assertCreated();
        $this->at(10, 21, 6, 5);
        $this->scan('time_out', $face)->assertOk();
        $this->at(10, 21, 22);
        $this->scan('time_in', $face)->assertCreated();

        $this->assertSame(2, Attendance::where('face_data_id', $face->id)->count());
        $this->assertSame(1, Attendance::where('face_data_id', $face->id)->whereNull('time_out')->count());
    }

    public function test_a_second_time_in_while_one_is_open_is_refused_and_a_second_time_out_too(): void
    {
        $this->at(10, 20, 9);
        $this->scan('time_in')->assertCreated();
        $this->at(10, 20, 9, 1);
        $this->scan('time_in')->assertStatus(409)->assertJsonPath('type', 'already_timed_in');

        $this->at(10, 20, 17);
        $this->scan('time_out')->assertOk();
        $this->scan('time_out')->assertStatus(409)->assertJsonPath('type', 'completed');
        $this->assertSame(1, Attendance::count());
    }

    public function test_the_computed_key_is_not_part_of_the_api(): void
    {
        $this->at(10, 20, 9);
        $this->scan('time_in')->assertCreated()->assertJsonMissingPath('attendance.open_key');
        $this->at(10, 20, 17);
        $this->scan('time_out')->assertOk()->assertJsonMissingPath('attendance.open_key');

        $this->assertArrayNotHasKey('open_key', Attendance::first()->toArray());
    }

    // ---- side effects --------------------------------------------------------

    public function test_a_retried_scan_makes_one_record_and_one_live_notice(): void
    {
        Event::fake([AttendanceRecorded::class]);
        $this->at(10, 20, 9);

        $this->scan('time_in')->assertCreated();
        $this->scan('time_in')->assertStatus(409);
        $this->scan('time_in')->assertStatus(409);

        $this->assertSame(1, Attendance::count());
        Event::assertDispatchedTimes(AttendanceRecorded::class, 1);
    }

    public function test_a_missed_time_out_makes_one_memo_however_often_the_engine_runs(): void
    {
        $this->at(10, 20, 9);
        $this->scan('time_in')->assertCreated();
        $this->at(10, 22, 12); // two days later: past the grace period

        $first = MemoEngine::draftMissedTimeOuts(now('Asia/Manila'));
        $second = MemoEngine::draftMissedTimeOuts(now('Asia/Manila'));

        $this->assertSame(1, $first);
        $this->assertSame(0, $second);
        $this->assertSame(1, EmployeeMemo::count());
    }

    // ---- migration prerequisite ----------------------------------------------

    public function test_the_migration_refuses_existing_duplicates_and_names_the_detection_sql(): void
    {
        $migration = require database_path('migrations/'.self::MIGRATION);

        $migration->down(); // take the constraint away to build the bad state
        $this->openRow();
        $this->openRow(['time_in' => '09:00:07']);

        try {
            $migration->up();
            $this->fail('The migration must stop while duplicate open attendance exists.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('time_out IS NULL', $e->getMessage());
            $this->assertStringContainsString('GROUP BY face_data_id, date', $e->getMessage());
            $this->assertStringContainsString('HAVING COUNT(*) > 1', $e->getMessage());
        }
        $this->assertSame(2, DB::table('attendances')->count(), 'The migration must not delete or merge anything.');

        DB::table('attendances')->where('time_in', '09:00:07')->delete(); // what the owner does by hand
        $migration->up();
        $this->assertTrue($this->hasOpenKey());
    }

    private function hasOpenKey(): bool
    {
        if (DB::getDriverName() === 'mysql') {
            return DB::table('information_schema.statistics')->where('table_schema', DB::getDatabaseName())->where('table_name', 'attendances')
                ->where('index_name', 'attendances_one_open_per_person_day_unique')->where('non_unique', 0)->exists();
        }

        return collect(DB::select("select name from sqlite_master where type = 'index' and tbl_name = 'attendances' and sql like 'CREATE UNIQUE%'"))->isNotEmpty();
    }
}
