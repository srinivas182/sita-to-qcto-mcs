<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Support\Database\Rls;

/** Summative assessment instruments per module, the assessor's decisions, and the provider's policy. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessment_settings', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('tenant_id')->unique()->constrained('tenants')->cascadeOnDelete();
            $t->unsignedSmallInteger('max_attempts')->default(3);
            $t->unsignedSmallInteger('random_pct')->default(25);
            $t->unsignedSmallInteger('new_assessor_first')->default(5);
            $t->unsignedSmallInteger('appeal_days')->default(14);
            $t->unsignedSmallInteger('appeal_decide_days')->default(21);
            $t->timestampsTz();
        });
        Schema::create('assessment_instruments', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $t->foreignUlid('module_id')->constrained('qualification_modules')->cascadeOnDelete();
            $t->string('method', 12); // test, observation, project, assignment, logbook
            $t->string('title', 200);
            $t->jsonb('criteria'); // [{key, text}]
            $t->unsignedSmallInteger('pass_mark')->nullable(); // tests: percentage
            $t->text('instructions')->nullable();
            $t->boolean('active')->default(true);
            $t->unsignedSmallInteger('sort')->default(0);
            $t->timestampsTz();
            $t->index(['tenant_id', 'module_id']);
        });
        Schema::create('assessment_results', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $t->foreignUlid('enrolment_id')->constrained('enrolments')->cascadeOnDelete();
            $t->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $t->foreignUlid('module_id')->constrained('qualification_modules')->cascadeOnDelete();
            $t->foreignUlid('instrument_id')->constrained('assessment_instruments')->cascadeOnDelete();
            $t->unsignedSmallInteger('attempt');
            $t->foreignUlid('assessor_id')->constrained('users');
            $t->ulid('credential_id')->nullable(); // the registration that was valid at the decision
            $t->string('outcome', 12); // competent, nyc
            $t->unsignedSmallInteger('score')->nullable();
            $t->jsonb('criteria'); // [{key, met, comment}]
            $t->jsonb('file_ids')->default('[]');
            $t->text('feedback')->nullable();
            $t->text('remediation')->nullable();
            $t->date('assessed_on');
            $t->timestampTz('decided_at');
            $t->uuid('client_id')->nullable(); // sent from the app's queue
            $t->string('hash', 64);
            $t->string('status', 12)->default('decided'); // decided, final, overturned
            $t->ulid('replaces_id')->nullable(); // an overturn or appeal outcome replaces a result
            $t->timestampsTz();
            $t->index(['tenant_id', 'enrolment_id', 'module_id']);
            $t->unique(['tenant_id', 'assessor_id', 'client_id']);
        });
        foreach (['assessment_settings', 'assessment_instruments', 'assessment_results'] as $table) {
            Rls::protect($table);
        }
    }

    public function down(): void
    {
        foreach (['assessment_results', 'assessment_instruments', 'assessment_settings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
