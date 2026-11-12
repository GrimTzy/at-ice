<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // HR can skip the SSS, PhilHealth and Pag-IBIG deduction for one employee (with a reason)
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->boolean('skip_gov_deductions')->default(false);
            $table->string('gov_skip_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['skip_gov_deductions', 'gov_skip_reason']);
        });
    }
};
