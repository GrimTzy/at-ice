<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The Driver a route is handed to (Driver Stores tab, owner 2026-11-11), and the
// dates it applies. The stores on the route follow it through driver_store_assignments.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_routes', function (Blueprint $table) {
            $table->foreignId('driver_id')->nullable()->after('name')->constrained('users')->nullOnDelete();
            $table->date('effective_from')->nullable()->after('driver_id');
            $table->date('effective_to')->nullable()->after('effective_from');
        });
    }

    public function down(): void
    {
        Schema::table('store_routes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('driver_id');
            $table->dropColumn(['effective_from', 'effective_to']);
        });
    }
};
