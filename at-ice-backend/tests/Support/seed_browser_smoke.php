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
    $store = App\Models\Customer::create(['name' => 'Deadline branch', 'customer_code' => 'CUST-TZ-001', 'branch_code' => 'TZ-001', 'is_active' => true]);
    App\Models\CustomerPrice::create(['customer_id' => $store->id, 'product_type' => 'bags', 'unit_price' => 50]);
    $tomorrow = now('Asia/Manila')->addDay()->startOfDay();
    $delivery = App\Models\Delivery::create([
        'delivery_no' => 'DEL-0001', 'customer_id' => $store->id, 'delivery_type' => 'outbound', 'status' => 'pending',
        'scheduled_date' => $tomorrow->toDateString(), 'ordered_at' => now(), 'due_at' => $tomorrow->copy()->setTime(8, 0),
        'prepared_by' => App\Models\User::where('role', 'admin')->firstOrFail()->id,
    ]);
    $delivery->items()->create(['product_type' => 'bags', 'quantity_ordered' => 5, 'unit_price' => 50]);
});
echo "Isolated browser fixtures ready.\n";
