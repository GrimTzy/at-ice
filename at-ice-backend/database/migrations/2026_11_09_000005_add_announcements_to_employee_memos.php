<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Announcements: policy notices from HR to many employees at once (picked
// employees, a role group, or everyone active). Disciplinary memos stay one
// employee each; only an announcement has no employee_id. Who receives it is
// worked out when HR releases it and kept in memo_recipients.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_memos', function (Blueprint $table) {
            $table->string('kind', 20)->default('disciplinary')->after('memo_no'); // disciplinary | announcement
            $table->json('audience')->nullable()->after('kind'); // announcement: {"mode":"employees|group|all","employee_ids":[],"group":"drivers"}
            $table->foreignId('employee_id')->nullable()->change();
        });

        Schema::create('memo_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('memo_id')->constrained('employee_memos')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();
            $table->unique(['memo_id', 'employee_id']);
            $table->index('employee_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memo_recipients');
        DB::table('employee_memos')->where('kind', 'announcement')->delete();
        Schema::table('employee_memos', function (Blueprint $table) {
            $table->foreignId('employee_id')->nullable(false)->change();
            $table->dropColumn(['kind', 'audience']);
        });
    }
};
