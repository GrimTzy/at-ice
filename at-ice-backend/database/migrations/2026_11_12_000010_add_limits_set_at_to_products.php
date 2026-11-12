<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// An item a Checker adds starts with default limits; limits_set_at stays empty
// until the Admin sets them (QA review, 2026-11-12), so the Admin can see which
// items still need limits. Items that exist now count as set.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->timestamp('limits_set_at')->nullable()->after('maximum_stock');
        });
        DB::table('products')->update(['limits_set_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('limits_set_at');
        });
    }
};
