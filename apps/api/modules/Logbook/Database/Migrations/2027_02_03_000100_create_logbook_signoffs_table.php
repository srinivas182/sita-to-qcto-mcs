<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Support\Database\Rls;

/** The mentor's final sign-off per learner and work experience module, and the assessor's check. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logbook_signoffs', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $t->foreignUlid('enrolment_id')->constrained('enrolments')->cascadeOnDelete();
            $t->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $t->foreignUlid('module_id')->constrained('qualification_modules')->cascadeOnDelete();
            $t->foreignUlid('mentor_id')->nullable()->constrained('employer_mentors')->nullOnDelete();
            $t->foreignUlid('signed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('signed_at');
            $t->unsignedInteger('minutes');
            $t->string('signature', 64); // sha256 over the module's signed entry hashes
            $t->string('statement_version', 10);
            $t->text('mentor_comment')->nullable();
            $t->string('status', 12)->default('signed'); // signed, checked, queried
            $t->foreignUlid('checked_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('checked_at')->nullable();
            $t->text('check_comment')->nullable();
            $t->timestampsTz();
            $t->unique(['tenant_id', 'enrolment_id', 'module_id']);
        });
        Rls::protect('logbook_signoffs');
        Schema::table('learner_placements', function (Blueprint $t): void {
            $t->timestampTz('nudged_at')->nullable(); // last "no entries for 14 days" reminder
        });
    }

    public function down(): void
    {
        Schema::table('learner_placements', fn (Blueprint $t) => $t->dropColumn('nudged_at'));
        Schema::dropIfExists('logbook_signoffs');
    }
};
