<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The employee record made from a hired applicant (HR > Interviews > Add as
// Employee). One applicant becomes at most one employee: the column is unique,
// and EmployeeController::store refuses an applicant who is not hired or is
// already linked. Existing rows stay NULL: older employees were never linked to
// their applicant, so nothing is guessed here.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applicants', function (Blueprint $table) {
            $table->foreignId('employee_id')->nullable()->unique()->constrained('employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('applicants', function (Blueprint $table) {
            $table->dropForeign(['employee_id']);
            $table->dropUnique(['employee_id']);
            $table->dropColumn('employee_id');
        });
    }
};
