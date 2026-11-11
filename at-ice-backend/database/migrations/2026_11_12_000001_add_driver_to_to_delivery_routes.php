<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// "No end date" with an optional Until (owner, 2026-11-12, from Kevin's
// 2a3e473): the regular Driver drives the route until driver_to (empty: until
// changed). After it the route has no regular Driver; its stops go to a
// reserve driver or Waiting. Admin is alerted once, 3 days before
// (driver_to_alerted_at). previous_driver_to: the Until of the previous
// regular Driver, who drives the route before driver_from.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_routes', function (Blueprint $table) {
            $table->date('driver_to')->nullable()->after('driver_from');
            $table->timestamp('driver_to_alerted_at')->nullable()->after('driver_to');
            $table->date('previous_driver_to')->nullable()->after('previous_driver_id');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_routes', function (Blueprint $table) {
            $table->dropColumn(['driver_to', 'driver_to_alerted_at', 'previous_driver_to']);
        });
    }
};
