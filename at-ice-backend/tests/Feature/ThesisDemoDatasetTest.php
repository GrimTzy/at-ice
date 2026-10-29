<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use App\Support\ThesisDemo\Context;
use App\Support\ThesisDemo\Manifest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// The thesis demo installer (php artisan thesis-demo): installs, is
// idempotent, matches the independent payroll calculation, leaves existing
// records alone, refuses unsafe cleanup, and its fictional employees are held
// to the real leave rules (rejected inputs are tested here, never inserted).
class ThesisDemoDatasetTest extends TestCase
{
    use RefreshDatabase;

    private const TABLES = ['users', 'employees', 'attendances', 'payrolls', 'customers', 'customer_prices', 'deliveries', 'receipts',
        'leave_requests', 'employee_memos', 'support_messages', 'products', 'stock_movements', 'suppliers', 'applicants', 'expenses', 'projects'];

    private string $out;

    private Employee $real;

    private int $hrId;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        $this->travelTo(now('Asia/Manila')->setDate(2026, 10, 29)->setTime(12, 0));
        $this->out = sys_get_temp_dir().'/thesis-demo-test-'.uniqid();
        $this->hrId = User::factory()->create(['role' => 'hr', 'email' => 'hr.test@example.com'])->id;
        User::factory()->create(['role' => 'admin', 'email' => 'admin.test@example.com']);
        // a real employee with a paid payroll, and a real customer
        $u = User::factory()->create(['role' => 'employee', 'sub_role' => 'checker']);
        $this->real = Employee::create([
            'user_id' => $u->id, 'employee_id' => 'EMP-0001', 'first_name' => 'Real', 'last_name' => 'Person', 'position' => 'Checker',
            'daily_rate' => 600, 'date_hired' => '2026-01-05', 'employment_type' => 'regular', 'shift' => 'day', 'shift_start' => '09:00:00',
            'shift_end' => '18:00:00', 'is_active' => true, 'employment_status' => 'regular', 'regularized_at' => '2026-07-05',
        ]);
        DB::table('payrolls')->insert(['employee_id' => $this->real->id, 'period_start' => '2026-09-28', 'period_end' => '2026-10-11',
            'status' => 'paid', 'prepared_by' => $this->hrId, 'net_pay' => 6000, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('customers')->insert(['customer_code' => 'CUST-REAL-001', 'name' => 'Real Store', 'customer_type' => 'dealer', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function install(): int
    {
        return $this->artisan('thesis-demo', [
            'action' => 'install', '--as-of' => '2026-10-29', '--hr' => 'hr.test@example.com', '--admin' => 'admin.test@example.com',
            '--existing' => 'EMP-0001', '--confirm' => DB::connection()->getDatabaseName(), '--out' => $this->out,
        ])->run();
    }

    private function counts(): array
    {
        return collect(self::TABLES)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
    }

    public function test_install_is_idempotent_reconciles_and_cleans_up_exactly(): void
    {
        $before = $this->counts();
        $realAttendanceBefore = DB::table('attendances')->where('employee_id', $this->real->id)->count();

        $this->assertSame(0, $this->install());
        $m = new Manifest(Context::DATASET);
        $total = $m->total();
        $this->assertGreaterThan(5000, $total);

        // rerun adds nothing
        $this->assertSame(0, $this->install());
        $this->assertSame($total, $m->total());

        // the real employee: seeded days only outside the period that has a payroll
        $seeded = DB::table('attendances')->where('employee_id', $this->real->id)->pluck('date')->map(fn ($d) => substr($d, 0, 10));
        $this->assertGreaterThan($realAttendanceBefore, $seeded->count());
        $this->assertFalse($seeded->contains(fn ($d) => $d >= '2026-09-28' && $d <= '2026-10-11'));
        $this->assertFalse($seeded->contains(fn ($d) => $d >= '2026-10-29'));
        $this->assertSame('Real', $this->real->fresh()->first_name);

        // verify: all checks pass, stored payroll = independent payroll
        $this->artisan('thesis-demo', ['action' => 'verify', '--out' => $this->out])->assertExitCode(0);
        $this->assertFileExists($this->out.'/THESIS-DEMO-PAYROLL.csv');
        $accounts = file_get_contents($this->out.'/THESIS-DEMO-ACCOUNTS.csv');
        $this->assertStringContainsString('@atice.invalid', $accounts);

        // no fictional delivery on or after the as-of date has a driver
        $this->assertSame(0, DB::table('deliveries')->whereIn('id', $m->ids('deliveries'))->where('scheduled_date', '>=', '2026-10-29')->whereNotNull('driver_id')->count());

        // cleanup: refused while a real payroll was computed from seeded attendance
        DB::table('payrolls')->insert(['employee_id' => $this->real->id, 'period_start' => '2026-10-12', 'period_end' => '2026-10-25',
            'status' => 'unpaid', 'prepared_by' => $this->hrId, 'created_at' => now(), 'updated_at' => now()]);
        $this->artisan('thesis-demo', ['action' => 'cleanup', '--confirm' => DB::connection()->getDatabaseName()])->assertExitCode(1);
        DB::table('payrolls')->where('employee_id', $this->real->id)->where('period_start', '2026-10-12')->delete();

        $this->artisan('thesis-demo', ['action' => 'cleanup', '--confirm' => DB::connection()->getDatabaseName(), '--include-derived' => true])->assertExitCode(0);
        $this->assertSame(0, $m->total());
        $this->assertSame($before, $this->counts());
        $this->assertSame($realAttendanceBefore, DB::table('attendances')->where('employee_id', $this->real->id)->count());
    }

    public function test_cleanup_refuses_when_a_real_delivery_uses_a_fictional_driver(): void
    {
        $this->install();
        $m = new Manifest(Context::DATASET);
        $driver = DB::table('employees')->where('id', $m->id('employee:E05'))->value('user_id');
        DB::table('deliveries')->insert(['delivery_no' => 'DEL-9999', 'driver_id' => $driver, 'customer_id' => DB::table('customers')->where('customer_code', 'CUST-REAL-001')->value('id'),
            'status' => 'pending', 'scheduled_date' => '2026-10-29', 'prepared_by' => $this->hrId, 'created_at' => now(), 'updated_at' => now()]);

        $this->artisan('thesis-demo', ['action' => 'cleanup', '--confirm' => DB::connection()->getDatabaseName(), '--include-derived' => true])
            ->expectsOutputToContain('real deliveries with a fictional driver')
            ->assertExitCode(1);
        $this->assertGreaterThan(0, $m->total());
    }

    public function test_seeded_employees_follow_the_real_leave_rules(): void
    {
        $this->install();
        $m = new Manifest(Context::DATASET);
        $user = fn (string $code) => User::find(DB::table('employees')->where('id', $m->id("employee:{$code}"))->value('user_id'));
        $file = fn (array $data) => $this->post('/api/my-leave-requests', $data + ['reason' => 'Test'], ['Accept' => 'application/json']);

        // probationary: refused
        Sanctum::actingAs($user('E03'));
        $file(['leave_type' => 'vacation', 'start_date' => '2026-12-01'])->assertStatus(422);

        Sanctum::actingAs($user('E12'));
        // sick leave more than one day after returning
        $file(['leave_type' => 'sick', 'start_date' => '2026-10-26', 'attachment' => UploadedFile::fake()->create('mc.pdf', 50, 'application/pdf')])->assertStatus(422);
        // vacation without 14 days notice
        $file(['leave_type' => 'vacation', 'start_date' => '2026-11-03'])->assertStatus(422);
        // overlap with the seeded pending vacation leave (L-01)
        $pending = DB::table('leave_requests')->where('id', $m->id('leave:L-01'))->first();
        $file(['leave_type' => 'vacation', 'start_date' => substr($pending->start_date, 0, 10)])->assertStatus(422);
        // a valid one goes through
        $file(['leave_type' => 'vacation', 'start_date' => '2026-12-14'])->assertStatus(201);
    }

    public function test_the_live_memo_engine_drafts_one_notice_to_explain_for_e19(): void
    {
        $this->install();
        $m = new Manifest(Context::DATASET);
        $this->travelTo(now('Asia/Manila')->setTime(12, 0));
        $this->assertSame(1, \App\Support\MemoEngine::draftMissedTimeOuts(now('Asia/Manila')));
        $this->assertSame(0, \App\Support\MemoEngine::draftMissedTimeOuts(now('Asia/Manila')));
        $memo = DB::table('employee_memos')->whereNotIn('id', $m->ids('employee_memos'))->first();
        $this->assertSame($m->id('employee:E19'), (int) $memo->employee_id);
        $this->assertSame('nte', $memo->level);
        $this->assertSame(4, (int) $memo->offense_no);
    }
}
