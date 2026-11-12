<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Reserve drivers (owner, 2026-11-11): drivers without a route who cover a
// driver that is off. They are given a stop before the regular drivers.
// A database that already moved the flag onto users (an earlier migration of
// the same name in another history) keeps it: the column is only added once.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'is_reserve_driver')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_reserve_driver')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'is_reserve_driver')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_reserve_driver');
        });
    }
};
