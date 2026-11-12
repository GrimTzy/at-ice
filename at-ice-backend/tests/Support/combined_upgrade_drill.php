<?php

// Uses only a dedicated SQLite fixture; never the developer's database.
$app = require __DIR__.'/browser_bootstrap.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
if (Schema::hasTable('users')) {
    throw new RuntimeException('Use a new upgrade-drill runtime.');
}
$added = [
    '2026_11_11_000004_dole_holiday_and_overtime_rates.php',
    '2026_11_11_000005_add_personal_purged_at_to_employees.php',
    '2026_11_11_000006_add_statutory_leave_types.php',
    '2026_11_11_000007_create_support_message_reads.php',
    '2026_11_11_000008_add_notices_read_at_to_users.php',
    '2026_11_11_000009_add_delivery_window_start.php',
    '2026_11_12_000010_add_limits_set_at_to_products.php',
    '2026_11_12_400000_align_web_token_lifetime.php',
];
$baseline = array_values(array_filter(glob(database_path('migrations/*.php')), fn ($path) => ! in_array(basename($path), $added, true)));
if ($kernel->call('migrate', ['--path' => $baseline, '--realpath' => true, '--force' => true]) !== 0) {
    throw new RuntimeException($kernel->output());
}
$now = now();
$user = DB::table('users')->insertGetId(['name' => 'Upgrade fixture', 'email' => 'upgrade@test.invalid', 'password' => bcrypt('Synthetic-only!1'), 'role' => 'driver', 'created_at' => $now, 'updated_at' => $now]);
$web = DB::table('personal_access_tokens')->insertGetId(['tokenable_type' => App\Models\User::class, 'tokenable_id' => $user, 'name' => 'auth_token', 'token' => hash('sha256', 'synthetic-web'), 'abilities' => '["*"]', 'created_at' => $now, 'updated_at' => $now, 'expires_at' => $now->copy()->addDay()]);
$appToken = DB::table('personal_access_tokens')->insertGetId(['tokenable_type' => App\Models\User::class, 'tokenable_id' => $user, 'name' => 'android-app', 'token' => hash('sha256', 'synthetic-app'), 'abilities' => '["*"]', 'created_at' => $now, 'updated_at' => $now]);
$product = DB::table('products')->insertGetId(['product_code' => 'UPGRADE-001', 'name' => 'Existing upgrade item', 'current_stock' => 42, 'minimum_stock' => 5, 'maximum_stock' => 100, 'created_at' => $now, 'updated_at' => $now]);
if ($kernel->call('migrate', ['--force' => true]) !== 0) {
    throw new RuntimeException($kernel->output());
}
$checks = [
    'existing_user_preserved' => DB::table('users')->where('id', $user)->value('email') === 'upgrade@test.invalid',
    'web_token_shortened_to_12h' => abs(now()->diffInMinutes(Illuminate\Support\Carbon::parse(DB::table('personal_access_tokens')->where('id', $web)->value('expires_at'))) - 720) < 1,
    'app_token_still_unlimited' => DB::table('personal_access_tokens')->where('id', $appToken)->value('expires_at') === null,
    'existing_stock_preserved' => (int) DB::table('products')->where('id', $product)->value('current_stock') === 42,
    'existing_limits_preserved_and_marked_set' => (int) DB::table('products')->where('id', $product)->value('minimum_stock') === 5 && DB::table('products')->where('id', $product)->value('limits_set_at') !== null,
    'new_support_reads_table' => Schema::hasTable('support_message_reads'),
    'new_window_columns' => Schema::hasColumn('deliveries', 'due_from') && Schema::hasColumn('delivery_contracts', 'delivery_from'),
    'retention_and_notice_columns' => Schema::hasColumn('employees', 'personal_purged_at') && Schema::hasColumn('users', 'notices_read_at'),
    'dated_rate_migration_applied' => (float) DB::table('pay_multipliers')->where('key', 'OT_ORDINARY')->where('effective_date', '2026-11-09')->value('value') === 1.25,
];
echo json_encode(['engine' => 'SQLite (MySQL still requires separate validation)', 'checks' => $checks], JSON_PRETTY_PRINT).PHP_EOL;
if (in_array(false, $checks, true)) {
    exit(1);
}
