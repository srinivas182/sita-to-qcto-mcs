<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Support\Database\Rls;

/** The moderation sample, moderators' decisions and reports, and learners' appeals. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('moderation_samples', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $t->foreignUlid('result_id')->constrained('assessment_results')->cascadeOnDelete();
            $t->foreignUlid('cohort_id')->constrained('cohorts')->cascadeOnDelete();
            $t->foreignUlid('module_id')->constrained('qualification_modules')->cascadeOnDelete();
            $t->foreignUlid('assessor_id')->constrained('users');
            $t->string('reason', 16); // nyc, new_assessor, facilitator, random
            $t->string('status', 8)->default('open'); // open, done
            $t->foreignUlid('moderator_id')->nullable()->constrained('users');
            $t->string('decision', 10)->nullable(); // agree, overturn
            $t->text('comment')->nullable();
            $t->ulid('new_result_id')->nullable();
            $t->timestampTz('decided_at')->nullable();
            $t->timestampsTz();
            $t->unique(['tenant_id', 'result_id']);
            $t->index(['tenant_id', 'cohort_id', 'module_id', 'status']);
        });
        Schema::create('moderation_reports', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $t->foreignUlid('cohort_id')->constrained('cohorts')->cascadeOnDelete();
            $t->foreignUlid('module_id')->constrained('qualification_modules')->cascadeOnDelete();
            $t->foreignUlid('moderator_id')->constrained('users');
            $t->ulid('credential_id')->nullable();
            $t->jsonb('summary'); // counts and the sample, as at signing
            $t->text('findings');
            $t->text('recommendations')->nullable();
            $t->string('hash', 64);
            $t->timestampTz('signed_at');
            $t->timestampsTz();
        });
        Schema::create('assessment_appeals', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $t->foreignUlid('result_id')->constrained('assessment_results')->cascadeOnDelete();
            $t->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $t->foreignUlid('cohort_id')->constrained('cohorts')->cascadeOnDelete();
            $t->text('reason');
            $t->timestampTz('lodged_at');
            $t->date('decide_by');
            $t->string('status', 10)->default('open'); // open, upheld, overturned
            $t->foreignUlid('decided_by')->nullable()->constrained('users');
            $t->text('decision_note')->nullable();
            $t->ulid('new_result_id')->nullable();
            $t->timestampTz('decided_at')->nullable();
            $t->timestampsTz();
            $t->index(['tenant_id', 'status', 'decide_by']);
        });
        foreach (['moderation_samples', 'moderation_reports', 'assessment_appeals'] as $table) {
            Rls::protect($table);
        }
    }

    public function down(): void
    {
        foreach (['assessment_appeals', 'moderation_reports', 'moderation_samples'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
