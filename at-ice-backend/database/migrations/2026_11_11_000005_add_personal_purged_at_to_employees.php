<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// When a deactivated employee's personal data (login, face, documents, contact,
// biodata) was deleted, a month after deactivation (QA review, 2026-11-11).
// The records DOLE needs stay until the retention period ends.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->timestamp('personal_purged_at')->nullable()->after('anonymized_at');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('personal_purged_at');
        });
    }
};
