<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Night differential (Labor Code Art. 86): hours worked from 10:00 PM to
// 6:00 AM and the extra pay for them, on its own payslip line.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            $table->decimal('night_hours', 6, 2)->default(0)->after('ot_hours');
            $table->decimal('night_diff_pay', 10, 2)->default(0)->after('overtime_pay');
        });
    }

    public function down(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            $table->dropColumn(['night_hours', 'night_diff_pay']);
        });
    }
};
