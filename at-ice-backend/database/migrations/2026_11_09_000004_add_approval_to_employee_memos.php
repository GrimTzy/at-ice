<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Formal memos (final written, NTE, zero tolerance): HR endorses the draft,
// Admin approves it or returns it to HR with a reason, then HR releases it.
// New statuses for_approval and approved use the existing status column.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_memos', function (Blueprint $table) {
            $table->foreignId('endorsed_by')->nullable()->after('issued_at')->constrained('users')->nullOnDelete();
            $table->timestamp('endorsed_at')->nullable()->after('endorsed_by');
            $table->foreignId('approved_by')->nullable()->after('endorsed_at')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->foreignId('returned_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            $table->timestamp('returned_at')->nullable()->after('returned_by');
            $table->string('return_reason', 1000)->nullable()->after('returned_at');
        });
    }

    public function down(): void
    {
        Schema::table('employee_memos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('endorsed_by');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropConstrainedForeignId('returned_by');
            $table->dropColumn(['endorsed_at', 'approved_at', 'returned_at', 'return_reason']);
        });
    }
};
