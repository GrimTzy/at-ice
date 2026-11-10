<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The reserve driver (client, 2026-11-10): a Maintenance employee with a
// driver's license who takes an absent Driver's route for the day.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->boolean('is_reserve_driver')->default(false)->after('license_expiry');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('is_reserve_driver');
        });
    }
};
