<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Who drives a route on a day (owner, 2026-11-11, Driver Stores: one day,
// one week or no end date). driver_id stays the regular Driver:
//   driver_from         the day a new regular Driver starts (empty: any day);
//                       before it previous_driver_id still drives the route
//   cover_driver_id     a temporary cover for one day or one week,
//   cover_from/to       on those days only; after them the regular Driver is
//                       back on his own
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_routes', function (Blueprint $table) {
            $table->date('driver_from')->nullable()->after('driver_id');
            $table->foreignId('previous_driver_id')->nullable()->after('driver_from')->constrained('users')->nullOnDelete();
            $table->foreignId('cover_driver_id')->nullable()->after('previous_driver_id')->constrained('users')->nullOnDelete();
            $table->date('cover_from')->nullable()->after('cover_driver_id');
            $table->date('cover_to')->nullable()->after('cover_from');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_routes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cover_driver_id');
            $table->dropConstrainedForeignId('previous_driver_id');
            $table->dropColumn(['driver_from', 'cover_from', 'cover_to']);
        });
    }
};
