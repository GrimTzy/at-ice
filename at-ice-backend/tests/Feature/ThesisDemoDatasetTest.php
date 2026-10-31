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

    // Existing (group) employees get paid history for the paid periods that
    // hold their seeded attendance; a period that already has a real payroll
    // is left alone, and the awaiting period stays for HR. After HR approves
    // the awaiting period live, the reconciliation still matches.
    public function test_existing_employees_get_paid_history_and_verify_matches_after_hr_approval(): void
    {
        // a second real employee hired mid-history: no payroll before the hire date
        $u = User::factory()->create(['role' => 'employee', 'sub_role' => 'salesman']);
        $late = Employee::create([
            'user_id' => $u->id, 'employee_id' => 'EMP-0002', 'first_name' => 'Late', 'last_name' => 'Hire', 'position' => 'Salesman',
            'daily_rate' => 610, 'date_hired' => '2026-10-02', 'employment_type' => 'regular', 'shift' => 'day', 'shift_start' => '09:00:00',
            'shift_end' => '18:00:00', 'is_active' => true, 'employment_status' => 'regular', 'regularized_at' => '2026-10-02',
        ]);
        $this->assertSame(0, $this->artisan('thesis-demo', [
            'action' => 'install', '--as-of' => '2026-10-29', '--hr' => 'hr.test@example.com', '--admin' => 'admin.test@example.com',
            '--existing' => 'EMP-0001,EMP-0002', '--confirm' => DB::connection()->getDatabaseName(), '--out' => $this->out,
        ])->run());
        $m = new Manifest(Context::DATASET);
        $seeded = DB::table('payrolls')->whereIn('id', $m->ids('payrolls'));

        // EMP-0001: seeded paid history, the real Sep 28 payroll untouched, nothing for the awaiting Oct 12 period
        $starts = (clone $seeded)->where('employee_id', $this->real->id)->orderBy('period_start')->pluck('period_start')->map(fn ($d) => substr($d, 0, 10))->all();
        $this->assertSame(['2026-08-03', '2026-08-17', '2026-08-31', '2026-09-14'], $starts);
        $this->assertSame(['paid'], (clone $seeded)->where('employee_id', $this->real->id)->distinct()->pluck('status')->all());
        $this->assertSame('6000.00', (string) DB::table('payrolls')->where('employee_id', $this->real->id)->where('period_start', '2026-09-28')->value('net_pay'));
        $this->assertFalse(DB::table('payrolls')->where('employee_id', $this->real->id)->where('period_start', '2026-10-12')->exists());

        // EMP-0002 (hired Oct 2): only Sep 28 to Oct 11, no government deductions carried from before the hire
        $p = (clone $seeded)->where('employee_id', $late->id)->get();
        $this->assertCount(1, $p);
        $this->assertSame('2026-09-28', substr($p[0]->period_start, 0, 10));
        $this->assertSame(0.0, (float) $p[0]->carry_over_in);
        $this->assertGreaterThan(0, (float) $p[0]->net_pay);

        $this->artisan('thesis-demo', ['action' => 'verify', '--out' => $this->out])->assertExitCode(0);

        // HR approves the awaiting period for both live: the check still matches
        Sanctum::actingAs(User::find($this->hrId));
        $this->postJson('/api/timesheets/approve', ['date' => '2026-10-25', 'employee_ids' => [$this->real->id, $late->id]])->assertOk();
        $this->artisan('thesis-demo', ['action' => 'verify', '--out' => $this->out])->assertExitCode(0);
        $csv = array_map('str_getcsv', file($this->out.'/THESIS-DEMO-PAYROLL.csv'));
        $head = array_flip($csv[0]);
        $late12 = collect($csv)->first(fn ($r) => ($r[$head['employee_code']] ?? '') === 'EMP-0002' && $r[$head['period_start']] === '2026-10-12');
        $this->assertSame('MATCH', $late12[$head['comparison']]);
        $this->assertFalse(collect($csv)->contains(fn ($r) => ($r[$head['employee_code']] ?? '') === 'EMP-0002' && $r[$head['period_start']] < '2026-09-28'));

        // cleanup: HR's payroll blocks it unless the seeded days are kept; the seeded payrolls never block
        $this->artisan('thesis-demo', ['action' => 'cleanup', '--confirm' => DB::connection()->getDatabaseName(), '--include-derived' => true])
            ->expectsOutputToContain('payrolls of existing employees computed from seeded attendance')
            ->assertExitCode(1);
        $this->artisan('thesis-demo', ['action' => 'cleanup', '--confirm' => DB::connection()->getDatabaseName(), '--include-derived' => true,
            '--keep-existing-attendance' => true])->assertExitCode(0);
        $this->assertTrue(DB::table('payrolls')->where('employee_id', $late->id)->where('period_start', '2026-10-12')->exists());
        $this->assertFalse(DB::table('payrolls')->where('employee_id', $late->id)->where('period_start', '2026-09-28')->exists());
    }

    // The later refresh on the defense day: after HR approved the awaiting
    // period (fictional and group) during testing, refresh rebuilds the
    // dataset for the new date, keeps HR's group payroll and passes verify.
    public function test_refresh_on_a_later_date_after_live_testing(): void
    {
        $this->install();
        Sanctum::actingAs(User::find($this->hrId));
        $ids = DB::table('employees')->where('is_active', true)->whereDate('date_hired', '<=', '2026-10-25')->pluck('id')->all();
        $this->postJson('/api/timesheets/approve', ['date' => '2026-10-25', 'employee_ids' => $ids])->assertOk();
        $hrPayroll = DB::table('payrolls')->where('employee_id', $this->real->id)->where('period_start', '2026-10-12')->value('id');
        $this->assertNotNull($hrPayroll);

        $this->travelTo(now('Asia/Manila')->setDate(2026, 11, 18)->setTime(9, 0));
        $this->assertSame(0, $this->artisan('thesis-demo', [
            'action' => 'refresh', '--as-of' => '2026-11-18', '--hr' => 'hr.test@example.com', '--admin' => 'admin.test@example.com',
            '--existing' => 'EMP-0001', '--confirm' => DB::connection()->getDatabaseName(), '--out' => $this->out,
            '--include-derived' => true, '--keep-existing-attendance' => true,
        ])->run());

        $m = new Manifest(Context::DATASET);
        $this->assertTrue(DB::table('payrolls')->where('id', $hrPayroll)->exists());
        $this->assertNotContains($hrPayroll, $m->ids('payrolls'));
        // fictional staff now have Oct 12 to 25 as seeded paid history; Oct 26 to Nov 8 awaits HR
        $e01 = $m->id('employee:E01');
        $this->assertSame('paid', DB::table('payrolls')->where('employee_id', $e01)->where('period_start', '2026-10-12')->value('status'));
        $this->assertFalse(DB::table('payrolls')->where('employee_id', $e01)->where('period_start', '2026-10-26')->exists());
        $this->assertSame(0, DB::table('attendances')->whereIn('id', $m->ids('attendances'))->where('date', '>=', '2026-11-18')->count());
        $this->artisan('thesis-demo', ['action' => 'verify', '--out' => $this->out])->assertExitCode(0);
    }

    // Small installer bugs found in the Oct 31 audit stay fixed.
    public function test_audit_fixes_stock_applicant_leave_and_review_times(): void
    {
        $this->install();
        $m = new Manifest(Context::DATASET);

        // an adjustment's quantity is the counted stock
        $adj = DB::table('stock_movements')->whereIn('id', $m->ids('stock_movements'))->where('type', 'adjustment')->first();
        $this->assertSame((int) $adj->stock_after, (int) $adj->quantity);

        // the released stock-out is written like StockOutRequestController@release
        $sor = DB::table('stock_out_requests')->whereIn('id', $m->ids('stock_out_requests'))->where('status', 'released')->first();
        $mv = DB::table('stock_movements')->where('id', $sor->stock_movement_id)->first();
        $this->assertSame('SOR-'.str_pad((string) $sor->id, 4, '0', STR_PAD_LEFT), $mv->reference_no);
        $this->assertSame((int) $sor->requested_by, (int) $mv->entered_by);
        $this->assertSame('maintenance', $mv->source);
        $this->assertSame($sor->released_at, $mv->created_at);

        // the hired applicant is the employee who starts after the as-of date
        $hired = DB::table('applicants')->whereIn('id', $m->ids('applicants'))->where('status', 'hired')->first();
        $this->assertSame('Joanna R. Castillo', $hired->name);
        $e20 = DB::table('employees')->where('id', $m->id('employee:E20'))->first();
        $this->assertStringContainsString($e20->employee_id, $hired->notes);
        $this->assertLessThan($e20->created_at, $hired->updated_at);

        // E06's leave on the as-of day (Thu Oct 29) and the next day; no rest day in L-01
        $l18 = DB::table('leave_requests')->where('id', $m->id('leave:L-18'))->first();
        $this->assertSame(['2026-10-29', '2026-10-30', '2.00'], [substr($l18->start_date, 0, 10), substr($l18->end_date, 0, 10), (string) $l18->total_days]);
        $l01 = DB::table('leave_requests')->where('id', $m->id('leave:L-01'))->first();
        foreach ([$l01->start_date, $l01->end_date] as $d) {
            $this->assertLessThanOrEqual(5, \Carbon\CarbonImmutable::parse($d)->dayOfWeekIso);
        }

        // declined change request: reviewed after it was filed
        $declined = DB::table('delivery_adjustment_requests')->whereIn('id', $m->ids('delivery_adjustment_requests'))->where('status', 'declined')->first();
        $this->assertGreaterThan($declined->created_at, $declined->reviewed_at);

        // verify passes, and catches a wrong adjustment quantity
        $this->artisan('thesis-demo', ['action' => 'verify', '--out' => $this->out])->assertExitCode(0);
        DB::table('stock_movements')->where('id', $adj->id)->update(['quantity' => 2]);
        $this->artisan('thesis-demo', ['action' => 'verify', '--out' => $this->out])
            ->expectsOutputToContain('FAIL every stock adjustment quantity')
            ->assertExitCode(1);
    }

    // The JSON columns are written in the shape the Admin and customer pages
    // read (r.items.map(i => i.before → i.after)); verify flags any other shape.
    public function test_json_columns_have_the_shape_the_app_reads(): void
    {
        $this->install();
        $m = new Manifest(Context::DATASET);

        $requests = DB::table('delivery_adjustment_requests')->whereIn('id', $m->ids('delivery_adjustment_requests'))->get();
        $this->assertCount(4, $requests);
        foreach ($requests as $r) {
            $items = json_decode($r->items, true);
            $this->assertTrue(array_is_list($items));
            foreach ($items as $i) {
                $this->assertSame(['after', 'before', 'product_type'], collect($i)->keys()->sort()->values()->all());
                $this->assertNotSame($i['before'], $i['after']);
            }
        }
        // "before" is the sacks on the delivery when asked (the pending one: the delivery as it is now)
        $pending = $requests->firstWhere('status', 'pending');
        $item = json_decode($pending->items, true)[0];
        $this->assertSame((int) DB::table('delivery_items')->where('delivery_id', $pending->delivery_id)->where('product_type', $item['product_type'])->sum('quantity_ordered'), $item['before']);

        // the admin endpoint the page calls returns the list shape
        Sanctum::actingAs(User::where('role', 'admin')->first());
        $row = collect($this->getJson('/api/delivery-adjustment-requests?status=all')->assertOk()->json())->firstWhere('id', $pending->id);
        $this->assertArrayHasKey('before', $row['items'][0]);

        // verify passes, and fails on the old object shape
        $this->artisan('thesis-demo', ['action' => 'verify', '--out' => $this->out])->assertExitCode(0);
        DB::table('delivery_adjustment_requests')->where('id', $pending->id)->update(['items' => json_encode(['bags' => 70])]);
        $this->artisan('thesis-demo', ['action' => 'verify', '--out' => $this->out])
            ->expectsOutputToContain('FAIL delivery_adjustment_requests.items')
            ->assertExitCode(1);
    }
}
