<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\FaceData;
use App\Models\KioskSetting;
use App\Models\KioskToken;
use App\Models\Payroll;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

// Real parallel requests on MySQL (duplicate payroll and duplicate attendance).
//
// Each request is a separate PHP process (tests/Support/send_request.php) that
// boots the app and sends one request through the HTTP kernel at the same instant,
// so each has its own database connection and the locks and unique keys are
// exercised for real. SQLite cannot do this (it has no row locks).
//
// Skipped unless the suite is pointed at a throwaway MySQL database whose name
// contains "test" or "audit". It runs migrate:fresh on that database, so the guard
// is deliberate. Example:
//   DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=atice_test \
//   DB_USERNAME=root DB_PASSWORD= php artisan test --filter=ConcurrentWritesMysqlTest
class ConcurrentWritesMysqlTest extends TestCase
{
    private string $kioskToken = 'concurrency-test-kiosk-token';

    protected function setUp(): void
    {
        // Checked first: migrate:fresh below wipes the database.
        if (getenv('DB_CONNECTION') !== 'mysql' || ! preg_match('/test|audit/i', (string) getenv('DB_DATABASE'))) {
            $this->markTestSkipped('MySQL-only test: set DB_CONNECTION=mysql and a DB_DATABASE that contains "test" or "audit".');
        }
        parent::setUp();
        // A fresh schema, not a rollback afterwards: the throwaway database is rebuilt for every test.
        $this->artisan('migrate:fresh', ['--force' => true]);

        $setting = KioskSetting::current();
        $setting->setPin('424242', null);
        KioskToken::create(['token_hash' => KioskToken::hash($this->kioskToken), 'pin_version' => $setting->fresh()->pin_version, 'expires_at' => now()->addDay()]);
    }

    private function employee(int $n): Employee
    {
        return Employee::create([
            'employee_id' => sprintf('EMP-%04d', $n), 'first_name' => "Worker{$n}", 'last_name' => 'Test', 'position' => 'Guard', 'daily_rate' => 700,
            'date_hired' => '2025-01-05', 'employment_type' => 'regular', 'shift' => 'day', 'shift_start' => '09:00', 'shift_end' => '18:00',
            'is_active' => true, 'employment_status' => 'regular', 'regularized_at' => '2025-01-05',
        ]);
    }

    private function face(Employee $e): FaceData
    {
        return FaceData::create(['worker_name' => "Worker {$e->id}", 'role' => 'guard', 'employee_id' => $e->id, 'descriptor' => array_fill(0, 128, 0.1)]);
    }

    private function hrToken(): string
    {
        return User::factory()->create(['role' => 'hr', 'is_active' => true])->createToken('concurrency-test')->plainTextToken;
    }

    /**
     * Starts every request in its own process, releases them together and returns
     * the "status body" lines in request order.
     *
     * @param  array<int, array{0: string, 1: string, 2: array<string, mixed>, 3?: string, 4?: string}>  $requests  [method, uri, body, bearer, kiosk]
     * @return array<int, array{status: int, body: string}>
     */
    private function parallel(array $requests): array
    {
        $startAt = (int) (microtime(true) * 1000) + 10000; // time for every process to boot
        $processes = [];
        foreach ($requests as $i => [$method, $uri, $body, $bearer, $kiosk]) {
            $processes[$i] = new Process([PHP_BINARY, 'tests/Support/send_request.php', $method, $uri, json_encode($body), $bearer ?? '', $kiosk ?? '', (string) $startAt], base_path(), null, null, 120);
            $processes[$i]->start();
        }
        $out = [];
        foreach ($processes as $i => $p) {
            $p->wait();
            $line = trim($p->getOutput());
            $this->assertNotSame('', $line, 'A request process printed nothing: '.$p->getErrorOutput());
            $out[$i] = ['status' => (int) strtok($line, ' '), 'body' => $line];
        }

        return $out;
    }

    private function statuses(array $results): array
    {
        $counts = array_count_values(array_column($results, 'status'));
        ksort($counts);

        return $counts;
    }

    public function test_parallel_time_ins_of_one_person_store_exactly_one_open_record(): void
    {
        $face = $this->face($this->employee(1));
        $requests = array_fill(0, 10, ['POST', 'api/attendances', ['face_data_id' => $face->id, 'type' => 'time_in'], '', $this->kioskToken]);

        $results = $this->parallel($requests);

        $this->assertSame([201 => 1, 409 => 9], $this->statuses($results), json_encode($results));
        $this->assertSame(1, Attendance::where('face_data_id', $face->id)->count());
        $this->assertSame(1, Attendance::where('face_data_id', $face->id)->whereNull('time_out')->count());
    }

    public function test_scans_of_different_people_do_not_block_each_other_and_each_gets_one_record(): void
    {
        $faces = array_map(fn ($n) => $this->face($this->employee($n)), range(1, 6));
        $requests = [];
        foreach ($faces as $f) {
            for ($k = 0; $k < 3; $k++) {
                $requests[] = ['POST', 'api/attendances', ['face_data_id' => $f->id, 'type' => 'time_in'], '', $this->kioskToken];
            }
        }

        $results = $this->parallel($requests);

        $this->assertSame([201 => 6, 409 => 12], $this->statuses($results), json_encode($results));
        $this->assertSame(6, Attendance::count());
        $this->assertSame(6, Attendance::distinct()->count('face_data_id'));
    }

    public function test_parallel_time_outs_close_the_record_once(): void
    {
        $e = $this->employee(1);
        $face = $this->face($e);
        $now = now(config('atice.timezone'));
        Attendance::create(['face_data_id' => $face->id, 'employee_id' => $e->id, 'date' => $now->toDateString(), 'time_in' => $now->copy()->subHours(3)->format('H:i:s'), 'status' => 'present']);

        $results = $this->parallel(array_fill(0, 6, ['POST', 'api/attendances', ['face_data_id' => $face->id, 'type' => 'time_out'], '', $this->kioskToken]));

        $this->assertSame([200 => 1, 409 => 5], $this->statuses($results), json_encode($results));
        $this->assertSame(1, Attendance::count());
        $this->assertNotNull(Attendance::first()->time_out);
    }

    public function test_after_a_time_out_the_next_time_in_is_accepted_even_while_other_scans_run(): void
    {
        $e = $this->employee(1);
        $face = $this->face($e);
        $other = $this->face($this->employee(2));
        $yesterday = now(config('atice.timezone'))->subDay();
        // yesterday's shift is complete
        Attendance::create(['face_data_id' => $face->id, 'employee_id' => $e->id, 'date' => $yesterday->toDateString(), 'time_in' => '09:00:00', 'time_out' => '18:00:00', 'hours_worked' => 9, 'status' => 'present']);

        $results = $this->parallel([
            ['POST', 'api/attendances', ['face_data_id' => $face->id, 'type' => 'time_in'], '', $this->kioskToken],
            ['POST', 'api/attendances', ['face_data_id' => $face->id, 'type' => 'time_in'], '', $this->kioskToken],
            ['POST', 'api/attendances', ['face_data_id' => $other->id, 'type' => 'time_in'], '', $this->kioskToken],
        ]);

        $this->assertSame([201 => 2, 409 => 1], $this->statuses($results), json_encode($results));
        $this->assertSame(2, Attendance::where('face_data_id', $face->id)->count());
        $this->assertSame(1, Attendance::where('face_data_id', $face->id)->whereNull('time_out')->count());
    }

    public function test_parallel_timesheet_approvals_keep_one_payroll_per_employee_and_never_deadlock(): void
    {
        $ids = array_map(fn ($n) => $this->employee($n)->id, [1, 2, 3]);
        $hr = $this->hrToken();
        $orders = [[$ids[0], $ids[1], $ids[2]], [$ids[2], $ids[1], $ids[0]], [$ids[1], $ids[2], $ids[0]], [$ids[0], $ids[2], $ids[1]], [$ids[2], $ids[0], $ids[1]], [$ids[1], $ids[0], $ids[2]]];
        $requests = array_map(fn ($order) => ['POST', 'api/timesheets/approve', ['date' => '2025-06-10', 'employee_ids' => $order], $hr, ''], $orders);

        $results = $this->parallel($requests);

        $this->assertSame([200 => 6], $this->statuses($results), json_encode($results));
        $this->assertSame(3, Payroll::count());
        $this->assertSame(3, Payroll::distinct()->count('employee_id'));
        $this->assertSame(['unpaid'], Payroll::pluck('status')->unique()->values()->all());
        // every approval is one audit entry per employee, none half-written: 6 requests x 3 employees
        $this->assertSame(18, DB::table('audit_logs')->where('action', 'timesheet.approved')->count());
    }

    public function test_approve_and_mark_paid_at_the_same_time_never_turn_a_paid_payroll_back_to_unpaid(): void
    {
        $ids = array_map(fn ($n) => $this->employee($n)->id, [1, 2]);
        $hr = $this->hrToken();
        $first = $this->parallel([['POST', 'api/timesheets/approve', ['date' => '2025-06-10', 'employee_ids' => $ids], $hr, '']]);
        $this->assertSame([200 => 1], $this->statuses($first));
        $payrollIds = Payroll::orderBy('id')->pluck('id')->all();

        $requests = [];
        for ($i = 0; $i < 4; $i++) {
            $requests[] = ['POST', 'api/payrolls/mark-paid', ['ids' => $payrollIds], $hr, ''];
            $requests[] = ['POST', 'api/timesheets/approve', ['date' => '2025-06-10', 'employee_ids' => $ids], $hr, ''];
        }

        $results = $this->parallel($requests);

        $this->assertSame([200 => 8], $this->statuses($results), json_encode($results));
        $this->assertSame(2, Payroll::count());
        $this->assertSame(['paid'], Payroll::pluck('status')->unique()->values()->all(), 'A paid payroll must stay paid.');
    }

    // MySQL words its duplicate-key errors differently from SQLite (it names the index).
    // A competing row appears just before the insert, in this process, on real MySQL:
    // the expected conflict is a clean 409 and nothing is half-written.
    public function test_the_real_mysql_key_conflicts_become_409_and_are_rolled_back(): void
    {
        $e = $this->employee(1);
        $face = $this->face($e);
        $today = now(config('atice.timezone'))->toDateString();
        Attendance::creating(function (Attendance $a) use ($today) {
            DB::table('attendances')->insert(['face_data_id' => $a->face_data_id, 'employee_id' => $a->employee_id, 'date' => $today, 'time_in' => '00:00:01', 'status' => 'present', 'created_at' => now(), 'updated_at' => now()]);
        });
        $this->withHeader('X-Kiosk-Token', $this->kioskToken)
            ->postJson('/api/attendances', ['face_data_id' => $face->id, 'type' => 'time_in'])
            ->assertStatus(409)->assertJsonPath('type', 'already_timed_in');
        $this->assertSame(0, Attendance::count(), 'The competing row is rolled back with the scan.');
        Attendance::flushEventListeners();

        $hr = User::factory()->create(['role' => 'hr', 'is_active' => true]);
        \Laravel\Sanctum\Sanctum::actingAs($hr);
        $period = \App\Support\PayPeriod::for('2025-06-10');
        Payroll::creating(function (Payroll $p) use ($period, $hr) {
            DB::table('payrolls')->insert(['employee_id' => $p->employee_id, 'period_start' => $period['start'], 'period_end' => $period['end'], 'status' => 'unpaid', 'prepared_by' => $hr->id, 'created_at' => now(), 'updated_at' => now()]);
        });
        $this->postJson('/api/timesheets/approve', ['date' => '2025-06-10', 'employee_ids' => [$e->id]])->assertStatus(409);
        $this->assertSame(0, Payroll::count());
    }

    // Lock order across the two writers: the approval walks employees by id, while
    // "mark paid" walks payrolls by payroll id. When the payroll ids run the other way
    // round (employee 2 was approved first), an approval holding payroll B and wanting
    // payroll A could wait for a mark-paid holding A and wanting B. Both must take
    // payroll locks in ascending payroll id so that cannot happen.
    public function test_approve_and_mark_paid_never_deadlock_when_payroll_ids_run_against_employee_ids(): void
    {
        $ids = array_map(fn ($n) => $this->employee($n)->id, [1, 2, 3, 4]);
        $hr = $this->hrToken();
        // approve in reverse employee order, so the payroll ids are 1 for the last employee, 4 for the first
        foreach (array_reverse($ids) as $id) {
            $this->parallel([['POST', 'api/timesheets/approve', ['date' => '2025-06-10', 'employee_ids' => [$id]], $hr, '']]);
        }
        $payrollIds = Payroll::orderBy('id')->pluck('id')->all();
        $this->assertCount(4, $payrollIds);

        $requests = [];
        for ($i = 0; $i < 6; $i++) {
            $requests[] = ['POST', 'api/timesheets/approve', ['date' => '2025-06-10', 'employee_ids' => $ids], $hr, ''];
            $requests[] = ['POST', 'api/payrolls/mark-paid', ['ids' => $payrollIds], $hr, ''];
        }

        $results = $this->parallel($requests);

        $this->assertSame([200 => 12], $this->statuses($results), json_encode($results));
        $this->assertSame(4, Payroll::count());
        $this->assertSame(['paid'], Payroll::pluck('status')->unique()->values()->all());
    }
}
