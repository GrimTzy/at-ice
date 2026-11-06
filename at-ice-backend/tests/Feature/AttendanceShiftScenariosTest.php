<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureKioskToken;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\EmployeeMemo;
use App\Models\FaceData;
use App\Support\MemoEngine;
use App\Support\PayPeriod;
use App\Support\PayrollCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// The shift scenarios that the one-open-record-per-
// person-and-day rule must not break, and what each one does to hours, memo drafts
// and the payroll timesheet. Every scenario except the same-date edge describes the
// behavior that existed BEFORE the fix, so these tests also pass on the old code
// (checked against the previous code); only the edge and the 409 handling are new.
class AttendanceShiftScenariosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnsureKioskToken::class);
    }

    private function person(string $code, string $start, string $end, string $shift): array
    {
        $e = Employee::create([
            'employee_id' => $code, 'first_name' => 'Ana', 'last_name' => $code, 'position' => 'Guard', 'daily_rate' => 700,
            'date_hired' => '2026-01-05', 'employment_type' => 'regular', 'shift' => $shift, 'shift_start' => $start, 'shift_end' => $end,
            'is_active' => true, 'employment_status' => 'regular', 'regularized_at' => '2026-01-05',
        ]);
        $f = FaceData::create(['worker_name' => $code, 'role' => 'guard', 'employee_id' => $e->id, 'descriptor' => array_fill(0, 128, 0.1)]);

        return [$e, $f];
    }

    private function at(string $ymd, string $hm): void
    {
        $this->travelTo(CarbonImmutable::parse("{$ymd} {$hm}", 'Asia/Manila'));
    }

    private function scan(FaceData $f, string $type)
    {
        return $this->postJson('/api/attendances', ['face_data_id' => $f->id, 'type' => $type]);
    }

    private function timesheet(Employee $e, string $anyDateInPeriod): array
    {
        $p = PayPeriod::for($anyDateInPeriod);

        return PayrollCalculator::timesheet($e, CarbonImmutable::parse($p['start']), CarbonImmutable::parse($p['end']));
    }

    public function test_an_overnight_shift_closes_on_the_record_of_the_evening_it_started(): void
    {
        [$e, $f] = $this->person('EMP-0001', '22:00', '06:00', 'night');

        $this->at('2026-10-20', '21:55');
        $this->scan($f, 'time_in')->assertCreated();
        $this->at('2026-10-21', '06:30');
        $this->scan($f, 'time_out')->assertOk()->assertJsonPath('type', 'time_out');

        $row = Attendance::sole();
        $this->assertSame('2026-10-20', $row->date->toDateString());
        $this->assertSame('06:30:00', $row->time_out);
        $this->assertEquals(8.58, (float) $row->hours_worked); // 21:55 to 06:30
        $this->assertSame(30, (int) $row->ot_minutes);         // 30 minutes after the shift end
        $this->assertSame(0, (int) $row->late_minutes);

        $this->at('2026-10-24', '12:00');
        $this->assertSame(0, MemoEngine::draftMissedTimeOuts(now('Asia/Manila')));
        $sheet = $this->timesheet($e, '2026-10-20');
        $this->assertEquals(1, $sheet['days_present']);
        $this->assertSame(0, $sheet['missing_time_out']);
        $this->assertSame(30, $sheet['ot_pending_minutes']);
    }

    public function test_a_forgotten_time_out_yesterday_stays_open_and_todays_time_out_closes_todays_record(): void
    {
        [$e, $f] = $this->person('EMP-0002', '09:00', '18:00', 'day');

        $this->at('2026-10-20', '09:00');
        $this->scan($f, 'time_in')->assertCreated();            // never timed out
        $this->at('2026-10-21', '09:00');
        $this->scan($f, 'time_in')->assertCreated();            // 24 hours later: yesterday is a missed Time Out
        $this->at('2026-10-21', '18:00');
        $this->scan($f, 'time_out')->assertOk();                // must close TODAY's record

        $yesterday = Attendance::whereDate('date', '2026-10-20')->sole();
        $today = Attendance::whereDate('date', '2026-10-21')->sole();
        $this->assertNull($yesterday->time_out);
        $this->assertEquals(0, (float) $yesterday->hours_worked);
        $this->assertSame('18:00:00', $today->time_out);
        $this->assertEquals(9.0, (float) $today->hours_worked);

        // one memo draft, for yesterday only, however often the engine runs
        $this->at('2026-10-23', '12:00');
        $this->assertSame(1, MemoEngine::draftMissedTimeOuts(now('Asia/Manila')));
        $this->assertSame(0, MemoEngine::draftMissedTimeOuts(now('Asia/Manila')));
        $this->assertSame($yesterday->id, EmployeeMemo::sole()->attendance_id);

        $sheet = $this->timesheet($e, '2026-10-21');
        $this->assertEquals(2, $sheet['days_present']);
        $this->assertSame(1, $sheet['missing_time_out']);
    }

    // Step 1 of the missed-Time-Out rules: a late Time Out inside the 16 hours is accepted.
    public function test_a_late_time_out_within_sixteen_hours_still_closes_the_record_with_the_real_time(): void
    {
        [$e, $f] = $this->person('EMP-0009', '09:00', '18:00', 'day');

        $this->at('2026-10-20', '09:00');
        $this->scan($f, 'time_in')->assertCreated();
        $this->at('2026-10-20', '23:30'); // 14.5 hours later, still inside the limit
        $this->scan($f, 'time_out')->assertOk();

        $row = Attendance::sole();
        $this->assertSame('23:30:00', $row->time_out);
        $this->assertEquals(14.5, (float) $row->hours_worked);
        $this->at('2026-10-23', '12:00');
        $this->assertSame(0, MemoEngine::draftMissedTimeOuts(now('Asia/Manila')));
        $this->assertSame(0, $this->timesheet($e, '2026-10-20')['missing_time_out']);
    }

    // Step 2: after the limit the record stays open, counts as a day present with 0 hours,
    // and the memo is the only trace; HR cannot give it a Time Out.
    public function test_hr_cannot_give_a_missed_record_a_time_out(): void
    {
        [, $f] = $this->person('EMP-0010', '09:00', '18:00', 'day');
        $this->at('2026-10-20', '09:00');
        $this->scan($f, 'time_in')->assertCreated();
        $row = Attendance::sole();

        \Laravel\Sanctum\Sanctum::actingAs(\App\Models\User::factory()->create(['role' => 'hr', 'is_active' => true]));
        $this->putJson("/api/attendances/{$row->id}", ['time_out' => '18:00:00'])->assertStatus(422);

        $this->assertNull($row->fresh()->time_out);
    }

    public function test_a_second_shift_on_the_same_date_after_a_completed_one_is_still_refused(): void
    {
        [, $f] = $this->person('EMP-0003', '09:00', '18:00', 'day');

        $this->at('2026-10-20', '09:00');
        $this->scan($f, 'time_in')->assertCreated();
        $this->at('2026-10-20', '13:00');
        $this->scan($f, 'time_out')->assertOk();
        $this->at('2026-10-20', '14:00');
        $this->scan($f, 'time_in')->assertStatus(409)->assertJsonPath('type', 'completed');

        $this->assertSame(1, Attendance::count());
    }

    public function test_a_day_shift_the_morning_after_a_night_shift_is_a_new_record_and_time_out_picks_it(): void
    {
        [, $f] = $this->person('EMP-0004', '22:00', '06:00', 'night');

        $this->at('2026-10-20', '22:00');
        $this->scan($f, 'time_in')->assertCreated();
        $this->at('2026-10-21', '06:05');
        $this->scan($f, 'time_out')->assertOk();                // closes the 20th
        $this->at('2026-10-21', '14:00');
        $this->scan($f, 'time_in')->assertCreated();            // the 21st: a new date, a new record
        $this->at('2026-10-21', '18:00');
        $this->scan($f, 'time_out')->assertOk();                // must close the 21st, not the 20th again

        $this->assertSame(2, Attendance::count());
        $this->assertSame(0, Attendance::whereNull('time_out')->count());
        $this->assertSame('06:05:00', Attendance::whereDate('date', '2026-10-20')->sole()->time_out);
        $this->assertSame('18:00:00', Attendance::whereDate('date', '2026-10-21')->sole()->time_out);
    }

    public function test_the_note_of_an_open_record_can_still_be_edited(): void
    {
        [, $f] = $this->person('EMP-0005', '09:00', '18:00', 'day');
        $this->at('2026-10-20', '09:00');
        $this->scan($f, 'time_in')->assertCreated();
        $row = Attendance::sole();

        \Laravel\Sanctum\Sanctum::actingAs(\App\Models\User::factory()->create(['role' => 'hr', 'is_active' => true]));
        $this->putJson("/api/attendances/{$row->id}", ['notes' => 'Forgot to time out, left at 17:30'])->assertOk();

        $this->assertSame('Forgot to time out, left at 17:30', $row->fresh()->notes);
        $this->assertNull($row->fresh()->time_out);
    }

    // The one behavior that is new: an open record older than 16 hours that is still on
    // the SAME calendar date. Before, a second open record was created; now the scan is
    // refused, and the message says what is wrong (it used to be a misleading "Time In
    // already recorded. Select Time Out", which the person cannot do).
    public function test_a_time_in_over_a_stale_record_of_the_same_date_is_refused_with_an_honest_message(): void
    {
        [, $f] = $this->person('EMP-0006', '05:00', '14:00', 'day');

        $this->at('2026-10-20', '05:00');
        $this->scan($f, 'time_in')->assertCreated();
        $this->at('2026-10-20', '21:30'); // 16.5 hours later, same date: the first one is a missed Time Out
        $this->scan($f, 'time_in')->assertStatus(409)
            ->assertJsonPath('type', 'missed_time_out')
            ->assertJsonPath('message', 'Your Time In earlier today has no Time Out. Please inform HR.');

        $this->assertSame(1, Attendance::count());
    }

    // ---- 409 only for the expected conflict; other database errors are not hidden ----

    public function test_only_the_open_record_conflict_becomes_409(): void
    {
        [, $f] = $this->person('EMP-0007', '09:00', '18:00', 'day');
        $this->at('2026-10-20', '09:00');
        Attendance::creating(function () {
            throw new UniqueConstraintViolationException('sqlite', 'insert into attendances', [], new \Exception("UNIQUE constraint failed: index 'attendances_one_open_per_person_day_unique'"));
        });

        $this->scan($f, 'time_in')->assertStatus(409)->assertJsonPath('type', 'already_timed_in');
    }

    public function test_any_other_unique_violation_is_not_reported_as_a_duplicate_scan(): void
    {
        [, $f] = $this->person('EMP-0008', '09:00', '18:00', 'day');
        $this->at('2026-10-20', '09:00');
        Attendance::creating(function () {
            throw new UniqueConstraintViolationException('sqlite', 'insert into attendances', [], new \Exception('UNIQUE constraint failed: some_other_table.some_column'));
        });

        $this->scan($f, 'time_in')->assertStatus(500);
        $this->assertSame(0, Attendance::count());
    }
}
