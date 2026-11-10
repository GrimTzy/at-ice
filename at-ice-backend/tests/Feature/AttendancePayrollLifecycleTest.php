<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\FaceData;
use App\Models\KioskSetting;
use App\Models\Payroll;
use App\Models\User;
use App\Support\PayPeriod;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AttendancePayrollLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_kiosk_time_in_out_approval_and_payment_keep_one_paid_payroll(): void
    {
        $this->travelTo(Carbon::parse('2026-10-14 08:00:00', 'Asia/Manila'));
        $employee = Employee::create(['employee_id' => 'LIFE-0001', 'first_name' => 'Lifecycle', 'last_name' => 'Worker', 'position' => 'Guard', 'daily_rate' => 600, 'date_hired' => '2026-01-05', 'employment_type' => 'regular', 'employment_status' => 'regular', 'shift' => 'day', 'shift_start' => '08:00', 'shift_end' => '17:00', 'is_active' => true]);
        $face = FaceData::create(['employee_id' => $employee->id, 'worker_name' => 'Lifecycle Worker', 'role' => 'guard', 'descriptor' => array_fill(0, 128, 0.1)]);
        KioskSetting::current()->setPin('4242', null);
        $token = $this->postJson('/api/kiosk/unlock', ['device_id' => 'kiosk-0123456789abcdef', 'pin' => '4242'])->assertOk()->json('token');
        $this->assertNotEmpty($token);
        $this->withHeader('X-Kiosk-Token', $token);
        $this->postJson('/api/attendances', ['face_data_id' => $face->id, 'type' => 'time_in'])->assertCreated();
        $this->postJson('/api/attendances', ['face_data_id' => $face->id, 'type' => 'time_in'])->assertStatus(409);
        $this->travelTo(Carbon::parse('2026-10-14 17:00:00', 'Asia/Manila'));
        // Unlock again: the kiosk token may have expired during the simulated shift.
        $token = $this->postJson('/api/kiosk/unlock', ['device_id' => 'kiosk-0123456789abcdef', 'pin' => '4242'])->assertOk()->json('token');
        $this->withHeader('X-Kiosk-Token', $token);
        $this->postJson('/api/attendances', ['face_data_id' => $face->id, 'type' => 'time_out'])->assertOk();
        $this->assertDatabaseCount('attendances', 1);
        $this->assertNotNull(Attendance::first()->time_out);

        $period = PayPeriod::for('2026-10-14');
        $this->travelTo(Carbon::parse($period['end'], 'Asia/Manila')->addDay()->setTime(9, 0));
        Sanctum::actingAs(User::factory()->create(['role' => 'hr']));
        $approve = ['date' => '2026-10-14', 'employee_ids' => [$employee->id]];
        $this->postJson('/api/timesheets/approve', $approve)->assertOk();
        $payroll = Payroll::first();
        $this->assertNotNull($payroll);
        $this->assertGreaterThan(0, (float) $payroll->gross_pay);
        $this->assertSame('unpaid', $payroll->status);
        $this->postJson('/api/payrolls/mark-paid', ['ids' => [$payroll->id]])->assertOk()->assertJsonPath('paid', 1);
        $this->postJson('/api/payrolls/mark-paid', ['ids' => [$payroll->id]])->assertOk()->assertJsonPath('paid', 0);
        $this->postJson('/api/timesheets/approve', $approve)->assertOk();
        $this->assertDatabaseCount('payrolls', 1);
        $this->assertSame('paid', $payroll->fresh()->status);
    }
}
