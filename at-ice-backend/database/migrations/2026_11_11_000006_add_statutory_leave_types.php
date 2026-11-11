<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The leave the law gives, apart from the credits (QA review, 2026-11-11):
// solo parent (RA 11861), VAWC (RA 9262) and the special leave for women
// (RA 9710) join maternity and paternity. "other" stays for old rows.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->enum('leave_type', ['sick', 'vacation', 'emergency', 'maternity', 'paternity', 'solo_parent', 'vawc', 'special_women', 'other'])->change();
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->enum('leave_type', ['sick', 'vacation', 'emergency', 'maternity', 'paternity', 'other'])->change();
        });
    }
};
