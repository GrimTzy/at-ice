<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Contact Support: a ticket can be escalated (with a reason) on top of its
// status. Escalated tickets stand out in the Admin inbox and its menu badge.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_messages', function (Blueprint $table) {
            $table->timestamp('escalated_at')->nullable()->after('assigned_to');
            $table->foreignId('escalated_by')->nullable()->after('escalated_at')->constrained('users')->nullOnDelete();
            $table->string('escalation_reason', 500)->nullable()->after('escalated_by');
        });
    }

    public function down(): void
    {
        Schema::table('support_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('escalated_by');
            $table->dropColumn(['escalated_at', 'escalation_reason']);
        });
    }
};
