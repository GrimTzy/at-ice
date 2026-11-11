<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A delivery has a From-To time window (QA review, 2026-11-11): due_from is when
// it may arrive at the earliest, due_at (Deliver by) stays the deadline. A mall
// that needs its ice before it opens gets e.g. 6:00-8:00 AM. Both stay
// optional so older deliveries keep working with their single Deliver by time.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->timestamp('due_from')->nullable()->after('due_at');
        });
        Schema::table('delivery_contracts', function (Blueprint $table) {
            $table->time('delivery_from')->nullable()->after('delivery_time');
        });
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropColumn('due_from');
        });
        Schema::table('delivery_contracts', function (Blueprint $table) {
            $table->dropColumn('delivery_from');
        });
    }
};
