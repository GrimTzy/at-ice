<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// A store now hears about every change to its delivery (driver changed,
// moved to another driver from a date, cancelled), not only "may be delayed".
// kind says which; the old one-notice-per-delivery rule stays for "delay".
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_notifications', function (Blueprint $table) {
            $table->string('kind', 20)->default('delay')->after('channel'); // delay | driver_changed | cancelled
            // the new index first: MySQL needs one on delivery_id for its foreign key
            $table->index(['delivery_id', 'channel', 'kind']);
        });
        Schema::table('delivery_notifications', function (Blueprint $table) {
            $table->dropUnique(['delivery_id', 'channel']);
        });
    }

    public function down(): void
    {
        DB::table('delivery_notifications')->where('kind', '!=', 'delay')->delete();
        Schema::table('delivery_notifications', function (Blueprint $table) {
            $table->unique(['delivery_id', 'channel']);
        });
        Schema::table('delivery_notifications', function (Blueprint $table) {
            $table->dropIndex(['delivery_id', 'channel', 'kind']);
            $table->dropColumn('kind');
        });
    }
};
