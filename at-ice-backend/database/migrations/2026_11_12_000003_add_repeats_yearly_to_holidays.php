<?php

use App\Support\HolidayYear;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// "Repeats every year" on a holiday (owner, 2026-11-12): only these are
// carried over by "Generate next year's holidays" (App\Support\HolidayYear).
// New holidays default to yes. Saved ones: the Philippine regular and special
// holidays yes, every other name (tests, declared one-time days) no.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('holidays', function (Blueprint $table) {
            $table->boolean('repeats_yearly')->default(true)->after('is_double');
        });

        DB::table('holidays')->select(['id', 'name'])->orderBy('id')->each(function ($h) {
            DB::table('holidays')->where('id', $h->id)->update(['repeats_yearly' => HolidayYear::isOfficial($h->name)]);
        });
    }

    public function down(): void
    {
        Schema::table('holidays', function (Blueprint $table) {
            $table->dropColumn('repeats_yearly');
        });
    }
};
