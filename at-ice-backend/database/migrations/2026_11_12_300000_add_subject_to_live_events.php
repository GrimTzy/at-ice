<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The record a notice is about (for the phone: the app opens that record). subject_type is the
// record's kind (delivery, leave_request, product, ...), subject_id its id.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('live_events', function (Blueprint $table) {
            $table->string('subject_type', 40)->nullable()->after('module');
            $table->unsignedBigInteger('subject_id')->nullable()->after('subject_type');
        });
    }

    public function down(): void
    {
        Schema::table('live_events', function (Blueprint $table) {
            $table->dropColumn(['subject_type', 'subject_id']);
        });
    }
};
