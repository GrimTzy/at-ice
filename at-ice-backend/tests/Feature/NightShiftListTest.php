<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Employee lists flag night-shift staff (is_night) for the Day / Night filter.
class NightShiftListTest extends TestCase
{
    use RefreshDatabase;

    private function guard(string $code, string $start, string $end): Employee
    {
        return Employee::create([
            'employee_id' => $code, 'first_name' => 'Arnel', 'last_name' => 'Ramos '.$code,
            'position' => 'Guard', 'daily_rate' => 600, 'date_hired' => '2026-01-05',
            'employment_type' => 'regular', 'shift' => $end <= $start ? 'night' : 'day',
            'shift_start' => $start, 'shift_end' => $end, 'is_active' => true,
        ]);
    }

    public function test_crossing_midnight_or_the_settings_night_hours_is_night(): void
    {
        Settings::set('attendance.night_shift_start', '18:00');
        Settings::set('attendance.night_shift_end', '23:00');
        $this->guard('EMP-0201', '07:00', '16:00');
        $this->guard('EMP-0202', '21:00', '06:00');
        $this->guard('EMP-0203', '18:00', '23:00');

        Sanctum::actingAs(User::factory()->create(['role' => 'hr']));
        $list = collect($this->getJson('/api/employees')->assertOk()->json())->keyBy('employee_id');

        $this->assertFalse($list['EMP-0201']['is_night']);
        $this->assertTrue($list['EMP-0202']['is_night']);
        $this->assertTrue($list['EMP-0203']['is_night']);
    }

    public function test_the_admin_summary_has_the_same_flag(): void
    {
        $this->guard('EMP-0204', '22:00', '07:00');
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->getJson('/api/employees-summary')->assertOk()->assertJsonPath('0.is_night', true);
    }
}
