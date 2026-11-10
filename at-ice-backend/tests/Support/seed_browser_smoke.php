<?php

$app = require __DIR__.'/browser_bootstrap.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
if (Illuminate\Support\Facades\Schema::hasTable('users')) {
    throw new RuntimeException('Use a new test runtime; existing fixture data will not be reset.');
}
if ($kernel->call('migrate', ['--force' => true]) !== 0) {
    throw new RuntimeException($kernel->output());
}
Illuminate\Database\Eloquent\Model::withoutEvents(function () {
    foreach (['admin', 'driver'] as $role) {
        $user = App\Models\User::create([
            'name' => 'Smoke '.ucfirst($role), 'email' => $role.'@browser-test.invalid',
            'password' => 'Browser-Test!2026', 'role' => $role, 'is_active' => true, 'must_change_password' => false,
        ]);
        if ($role === 'driver') {
            $employee = App\Models\Employee::create([
                'user_id' => $user->id, 'employee_id' => 'SMOKE-0001', 'first_name' => 'Smoke', 'last_name' => 'Driver',
                'position' => 'Driver', 'daily_rate' => 600, 'date_hired' => '2026-01-01', 'is_active' => true,
                'shift_start' => '00:00', 'shift_end' => '23:59',
            ]);
            App\Models\Attendance::create(['employee_id' => $employee->id, 'date' => now('Asia/Manila')->toDateString(), 'time_in' => '00:00:00', 'status' => 'present']);
        }
    }
});
echo "Isolated browser fixtures ready.\n";
