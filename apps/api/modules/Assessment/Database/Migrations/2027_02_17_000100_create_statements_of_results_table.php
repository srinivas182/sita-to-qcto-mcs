<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Support\Database\Rls;

/** The provider's Statement of Results per enrolment: a snapshot of the final module results. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statements_of_results', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $t->foreignUlid('enrolment_id')->constrained('enrolments')->cascadeOnDelete();
            $t->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $t->foreignUlid('cohort_id')->constrained('cohorts')->cascadeOnDelete();
            $t->string('number', 40);
            $t->foreignUlid('issued_by')->constrained('users');
            $t->timestampTz('issued_at');
            $t->jsonb('snapshot'); // learner, qualification, modules, work experience, as issued
            $t->string('verify_code', 10)->unique();
            $t->string('hash', 64);
            $t->string('status', 10)->default('issued'); // issued, withdrawn
            $t->text('withdrawn_reason')->nullable();
            $t->foreignUlid('withdrawn_by')->nullable()->constrained('users');
            $t->timestampTz('withdrawn_at')->nullable();
            $t->timestampsTz();
            $t->unique(['tenant_id', 'number']);
            $t->index(['tenant_id', 'enrolment_id', 'status']);
        });
        Rls::protect('statements_of_results');
    }

    public function down(): void
    {
        Schema::dropIfExists('statements_of_results');
    }
};
