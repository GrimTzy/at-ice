<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Payroll;
use App\Models\User;
use App\Support\PayPeriod;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

// Approving the same timesheets more than once at
// the same moment made several payroll rows for one employee and pay period.
// One payroll per employee and per pay period is the business rule: the pay
// period is identified by its start date (App\Support\PayPeriod). These tests
// run on SQLite, so they prove the rule, the rollback and the migration guard,
// not real parallelism: ConcurrentWritesMysqlTest runs the real parallel
// requests on MySQL.
class PayrollApprovalIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_11_06_000001_payrolls_one_per_employee_and_period.php';

    /** @var array<int, Employee> */
    private array $employees = [];

    protected function setUp(): void
    {
        parent::setUp();
        // the day after the pay period of 2026-10-14 ended, whatever the period settings are
        $this->travelTo(Carbon::parse(PayPeriod::for('2026-10-14')['end'], 'Asia/Manila')->addDay()->setTime(9, 0));
        foreach ([1, 2, 3] as $n) {
            $this->employees[$n] = Employee::create([
                'employee_id' => sprintf('EMP-%04d', $n), 'first_name' => "Worker{$n}", 'last_name' => 'Test', 'position' => 'Guard',
                'daily_rate' => 700, 'date_hired' => '2026-01-05', 'employment_type' => 'regular', 'shift' => 'day',
                'shift_start' => '09:00', 'shift_end' => '18:00', 'is_active' => true, 'employment_status' => 'regular', 'regularized_at' => '2026-01-05',
            ]);
        }
        Sanctum::actingAs(User::factory()->create(['role' => 'hr', 'is_active' => true]));
    }

    private function approve(?array $ids = null)
    {
        return $this->postJson('/api/timesheets/approve', [
            'date' => '2026-10-14',
            'employee_ids' => $ids ?? array_map(fn ($e) => $e->id, $this->employees),
        ]);
    }

    public function test_the_database_allows_only_one_payroll_per_employee_and_period(): void
    {
        $this->approve()->assertOk();
        $row = (array) DB::table('payrolls')->orderBy('id')->first();
        unset($row['id']);

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('payrolls')->insert($row); // same employee, same period_start
    }

    public function test_approving_again_after_a_retry_keeps_one_row_per_employee(): void
    {
        $this->approve()->assertOk()->assertJsonPath('approved', 3);
        $this->approve()->assertOk()->assertJsonPath('approved', 3);

        $this->assertSame(3, Payroll::count());
        $this->assertSame(3, Payroll::distinct()->count('employee_id'));
        $this->assertSame(['unpaid'], Payroll::pluck('status')->unique()->values()->all());
    }

    public function test_a_failure_part_way_writes_no_payroll_and_no_audit_for_any_employee(): void
    {
        $second = $this->employees[2]->id;
        Payroll::saving(function (Payroll $payroll) use ($second) {
            if ((int) $payroll->employee_id === $second) {
                throw new RuntimeException('simulated failure on the second employee');
            }
        });

        $this->approve()->assertStatus(500);

        $this->assertSame(0, Payroll::count());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'timesheet.approved')->count());
    }

    public function test_a_paid_payroll_stays_untouched_and_the_others_are_approved(): void
    {
        $this->approve([$this->employees[1]->id])->assertOk();
        $paid = Payroll::first();
        $paid->update(['status' => 'paid', 'paid_at' => now()]);
        $netBefore = (string) $paid->fresh()->net_pay;

        $this->approve()->assertOk()->assertJsonPath('approved', 2)->assertJsonPath('skipped', ['EMP-0001']);

        $this->assertSame(3, Payroll::count());
        $this->assertSame('paid', $paid->fresh()->status);
        $this->assertSame($netBefore, (string) $paid->fresh()->net_pay);
    }

    public function test_only_the_payroll_key_conflict_becomes_409_and_nothing_is_written(): void
    {
        Payroll::saving(function () {
            throw new UniqueConstraintViolationException('sqlite', 'insert into payrolls', [], new RuntimeException("UNIQUE constraint failed: index 'payrolls_employee_period_unique'"));
        });

        $this->approve()->assertStatus(409);
        $this->assertSame(0, Payroll::count());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'timesheet.approved')->count());
    }

    // The real thing, with the database's own message (SQLite names the columns, MySQL
    // the index; both are recognized): a competing row appears just before the insert.
    public function test_a_real_key_conflict_is_409_and_rolls_the_whole_batch_back(): void
    {
        $period = PayPeriod::for('2026-10-14');
        $hr = User::where('role', 'hr')->value('id');
        Payroll::creating(function (Payroll $payroll) use ($period, $hr) {
            if ((int) $payroll->employee_id === $this->employees[2]->id && ! DB::table('payrolls')->where('employee_id', $payroll->employee_id)->exists()) {
                DB::table('payrolls')->insert([
                    'employee_id' => $payroll->employee_id, 'period_start' => $period['start'], 'period_end' => $period['end'],
                    'status' => 'unpaid', 'prepared_by' => $hr, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        $this->approve()->assertStatus(409);

        $this->assertSame(0, Payroll::count(), 'The competing row and the first employee are rolled back with the batch.');
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'timesheet.approved')->count());
    }

    public function test_any_other_unique_violation_is_not_reported_as_a_busy_message(): void
    {
        Payroll::saving(function () {
            throw new UniqueConstraintViolationException('sqlite', 'insert into audit_logs', [], new RuntimeException('UNIQUE constraint failed: some_other_table.some_column'));
        });

        $this->approve()->assertStatus(500);
        $this->assertSame(0, Payroll::count());
    }

    public function test_the_migration_refuses_existing_duplicates_and_names_the_detection_sql(): void
    {
        $migration = require database_path('migrations/'.self::MIGRATION);
        $this->approve([$this->employees[1]->id])->assertOk();

        $migration->down(); // take the constraint away to build the bad state
        $row = (array) DB::table('payrolls')->first();
        unset($row['id']);
        DB::table('payrolls')->insert($row);

        try {
            $migration->up();
            $this->fail('The migration must stop while duplicate payrolls exist.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('GROUP BY employee_id, period_start', $e->getMessage());
            $this->assertStringContainsString('HAVING COUNT(*) > 1', $e->getMessage());
        }
        $this->assertSame(2, DB::table('payrolls')->count(), 'The migration must not delete or merge anything.');

        DB::table('payrolls')->where('id', '>', 1)->delete(); // what the owner does by hand
        $migration->up();
        $this->assertTrue($this->uniqueExists());
    }

    private function uniqueExists(): bool
    {
        if (DB::getDriverName() === 'mysql') {
            return DB::table('information_schema.statistics')->where('table_schema', DB::getDatabaseName())->where('table_name', 'payrolls')
                ->where('index_name', 'payrolls_employee_period_unique')->where('non_unique', 0)->exists();
        }

        return collect(DB::select("select name from sqlite_master where type = 'index' and tbl_name = 'payrolls' and sql like 'CREATE UNIQUE%'"))->isNotEmpty();
    }
}
