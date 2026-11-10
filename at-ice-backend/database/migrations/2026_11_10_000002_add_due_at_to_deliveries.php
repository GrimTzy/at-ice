<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// "Deliver by": the time a delivery must reach the store, at the latest
// (owner, 2026-11-10). Delivered at or before it is on time, after it is late.
// Contract deliveries and urgent top-ups already kept that time in ordered_at;
// it is copied here. Older one-off deliveries have no deadline.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->dateTime('due_at')->nullable()->after('ordered_at');
            $table->index(['scheduled_date', 'due_at']);
        });

        DB::table('deliveries')
            ->where(fn ($q) => $q->whereNotNull('contract_id')->orWhere('is_top_up', true))
            ->whereNotNull('ordered_at')
            ->update(['due_at' => DB::raw('ordered_at')]);
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropIndex(['scheduled_date', 'due_at']);
            $table->dropColumn('due_at');
        });
    }
};
