<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Routes (owner, 2026-11-11): a named group of stores (Route A, Route B...)
// that Admin hands to a Driver. A store is on one route at most.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_routes', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            $table->timestamps();
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('store_route_id')->nullable()->after('is_active')->constrained('store_routes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('store_route_id');
        });
        Schema::dropIfExists('store_routes');
    }
};
