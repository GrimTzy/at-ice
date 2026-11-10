<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fixed delivery routes (client, 2026-11-10): 4 trucks, routes A to D, one
// regular Driver per route, one trip a day.
//   delivery_routes   a route and its regular Driver
//   route_stops       the stores on a route in delivery order. Tenants of one
//                     mall share a stop (same stop_order and stop_group); a
//                     store is on one route only.
// deliveries.route_id is the route a delivery was made for, so an absent
// Driver's whole route can go to the reserve driver.
// deliveries.rescheduled_from is the day a Waiting delivery was moved from.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_routes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('name', 100);
            $table->foreignId('driver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->string('notes', 1000)->nullable();
            $table->timestamps();
        });

        Schema::create('route_stops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('route_id')->constrained('delivery_routes')->cascadeOnDelete();
            $table->foreignId('customer_id')->unique()->constrained('customers')->cascadeOnDelete();
            $table->unsignedInteger('stop_order');
            $table->string('stop_group', 150)->nullable();
            $table->timestamps();
            $table->index(['route_id', 'stop_order']);
        });

        Schema::table('deliveries', function (Blueprint $table) {
            $table->foreignId('route_id')->nullable()->after('contract_id')->constrained('delivery_routes')->nullOnDelete();
            $table->date('rescheduled_from')->nullable()->after('scheduled_date');
        });
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('route_id');
            $table->dropColumn('rescheduled_from');
        });
        Schema::dropIfExists('route_stops');
        Schema::dropIfExists('delivery_routes');
    }
};
