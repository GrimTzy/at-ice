<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Reserve drivers (owner, 2026-11-11): the flag is on a Driver account
// (users.is_reserve_driver), not on a Maintenance employee
// (employees.is_reserve_driver, 2026_11_11_000002). A reserve driver has no
// route and takes an absent Driver's whole route when it fits the truck limits.
// A flag already on a Driver's employee record moves over; a Maintenance
// employee's does not (they need a Driver login to be a reserve).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_reserve_driver')->default(false)->after('is_active');
        });

        if (Schema::hasColumn('employees', 'is_reserve_driver')) {
            $driverUserIds = DB::table('employees')
                ->join('users', 'users.id', '=', 'employees.user_id')
                ->where('employees.is_reserve_driver', true)
                ->where('users.role', 'driver')
                ->pluck('users.id');
            if ($driverUserIds->isNotEmpty()) {
                DB::table('users')->whereIn('id', $driverUserIds)->update(['is_reserve_driver' => true]);
            }

            Schema::table('employees', function (Blueprint $table) {
                $table->dropColumn('is_reserve_driver');
            });
        }
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->boolean('is_reserve_driver')->default(false)->after('license_expiry');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_reserve_driver');
        });
    }
};
