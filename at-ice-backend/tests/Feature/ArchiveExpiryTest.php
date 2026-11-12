<?php

namespace Tests\Feature;

use App\Http\Controllers\EmployeeController;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\FaceData;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use ReflectionMethod;
use Tests\TestCase;

// R3 archive expiry (QA review, 2026-11-11): a month after deactivation the
// personal data goes; past the 3-year retention period (archive.expiry_action)
// the rest is anonymized (the default), left for Admin (REVIEW) or purged.
// Once a day, or with php artisan archive:expire.
class ArchiveExpiryTest extends TestCase
{
    use RefreshDatabase;

    private Employee $old;

    private Employee $recent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now('Asia/Manila')->setDate(2026, 10, 15)->setTime(9, 0));
        $base = ['position' => 'Guard', 'daily_rate' => 500, 'employment_type' => 'regular', 'shift' => 'day', 'is_active' => false];
        $this->old = Employee::create($base + ['employee_id' => 'EMP-0100', 'first_name' => 'Old', 'last_name' => 'Timer', 'phone' => '09171234567',
            'city' => 'San Jose del Monte', 'date_hired' => '2020-01-06', 'deactivated_at' => '2023-01-10']);
        $this->recent = Employee::create($base + ['employee_id' => 'EMP-0101', 'first_name' => 'New', 'last_name' => 'Leaver',
            'date_hired' => '2025-01-06', 'deactivated_at' => '2026-05-01']);
        FaceData::create(['worker_name' => 'Old Timer', 'role' => 'guard', 'employee_id' => $this->old->id, 'descriptor' => array_fill(0, 128, 0.1)]);
        Attendance::create(['employee_id' => $this->old->id, 'date' => '2022-03-01', 'time_in' => '08:55:00', 'status' => 'present']);
    }

    private function poll(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->getJson('/api/live-feed')->assertOk();
    }

    public function test_review_keeps_the_records_and_only_the_personal_data_goes(): void
    {
        Settings::set('archive.expiry_action', 'REVIEW');
        $this->poll();
        $this->artisan('archive:expire')->assertSuccessful();
        $old = $this->old->fresh();
        // what DOLE needs stays: name, code, attendance
        $this->assertSame('Old', $old->first_name);
        $this->assertSame('EMP-0100', $old->employee_id);
        $this->assertSame(1, Attendance::count());
        $this->assertNull($old->anonymized_at);
        // a month after deactivation the personal data went anyway (data privacy)
        $this->assertNotNull($old->personal_purged_at);
        $this->assertNull($old->phone);
        $this->assertSame(0, FaceData::count());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'like', 'retention.%')->where('action', '!=', 'retention.personal_purged')->count());
    }

    public function test_the_personal_data_goes_a_month_after_deactivation(): void
    {
        $user = User::factory()->create(['role' => 'employee', 'sub_role' => 'checker', 'email' => 'santiago.0102@atice.cloud']);
        $leaver = Employee::create(['user_id' => $user->id, 'employee_id' => 'EMP-0102', 'first_name' => 'Rhea', 'last_name' => 'Santiago',
            'position' => 'Checker', 'daily_rate' => 610, 'employment_type' => 'regular', 'shift' => 'day', 'is_active' => false,
            'phone' => '09181234567', 'city' => 'Malolos', 'emergency_name' => 'Rodel Santiago', 'sss_no' => '34-5678901-2',
            'date_hired' => '2025-02-03', 'deactivated_at' => '2026-09-20']);
        Attendance::create(['employee_id' => $leaver->id, 'date' => '2026-09-18', 'time_in' => '08:55:00', 'status' => 'present']);

        // 25 days after deactivation: nothing yet
        $this->poll();
        $this->assertSame('09181234567', $leaver->fresh()->phone);

        // 31 days after
        $this->travelTo(now('Asia/Manila')->setDate(2026, 10, 21)->setTime(9, 0));
        $this->poll();
        $leaver = $leaver->fresh();
        $this->assertNotNull($leaver->personal_purged_at);
        $this->assertNull($leaver->phone);
        $this->assertNull($leaver->city);
        $this->assertNull($leaver->emergency_name);
        $this->assertSame('Rhea', $leaver->first_name);       // kept for the payroll records
        $this->assertSame('34-5678901-2', $leaver->sss_no);   // kept for the remittance records
        $this->assertSame(1, Attendance::where('employee_id', $leaver->id)->count());
        $this->assertFalse((bool) $user->fresh()->is_active);
        $this->assertStringEndsWith('@atice.invalid', $user->fresh()->email);

        // the login and details are gone: a returning worker is added again
        Sanctum::actingAs(User::factory()->create(['role' => 'hr']));
        $this->postJson("/api/employees/{$leaver->id}/reactivate")->assertStatus(422);
    }

    public function test_anonymize_removes_personal_details_and_frees_the_code(): void
    {
        $this->poll(); // ANONYMIZE is the default
        $old = $this->old->fresh();
        $this->assertNotNull($old->anonymized_at);
        $this->assertStringStartsWith('FMR-', $old->employee_id); // EMP-0100 may go to a new hire
        $this->assertSame('Former', $old->first_name);
        $this->assertNull($old->phone);
        $this->assertNull($old->city);
        $this->assertSame(0, FaceData::count());
        $this->assertSame(1, Attendance::count());
        $this->assertSame('New', $this->recent->fresh()->first_name); // inactive under 3 years
        $this->assertDatabaseHas('audit_logs', ['action' => 'retention.anonymized', 'subject_id' => $this->old->id]);

        // the next code is the lowest one free
        $next = new ReflectionMethod(EmployeeController::class, 'nextEmployeeCode');
        $this->assertSame('EMP-0001', $next->invoke(null));
        Employee::create(['employee_id' => 'EMP-0001', 'first_name' => 'Ana', 'last_name' => 'Ramos', 'position' => 'Guard', 'daily_rate' => 500, 'date_hired' => '2026-10-01', 'is_active' => true]);
        $this->assertSame('EMP-0002', $next->invoke(null));

        // Once a day: a second poll does nothing more; the review list is empty
        $this->poll();
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'retention.anonymized')->count());
        $this->assertSame([], $this->getJson('/api/retention-review')->json('employees'));
    }

    public function test_purge_deletes_the_employee_and_their_records(): void
    {
        Settings::set('archive.expiry_action', 'PURGE');
        $this->artisan('archive:expire')->expectsOutputToContain('PURGE: 1')->assertSuccessful();
        $this->assertNull(Employee::find($this->old->id));
        $this->assertSame(0, Attendance::count());
        $this->assertNotNull(Employee::find($this->recent->id));
        $this->assertDatabaseHas('audit_logs', ['action' => 'retention.deleted']);
    }

    public function test_a_kept_employee_is_not_touched_and_admin_can_anonymize_by_hand(): void
    {
        Settings::set('archive.expiry_action', 'PURGE');
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->postJson('/api/retention-review/keep', ['kind' => 'employee', 'ref' => $this->old->id])->assertOk();
        $this->artisan('archive:expire')->assertSuccessful();
        $this->assertNotNull(Employee::find($this->old->id));

        $this->postJson('/api/retention-review/anonymize', ['kind' => 'employee', 'ref' => $this->recent->id])->assertOk();
        $this->assertNotNull($this->recent->fresh()->anonymized_at);
    }
}
