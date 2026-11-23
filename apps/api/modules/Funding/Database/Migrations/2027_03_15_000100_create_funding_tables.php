<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Support\Database\Rls;

return new class extends Migration
{
    public function up(): void
    {
        // Who pays: a SETA, an employer or another funder. Per provider.
        Schema::create('funders', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('name', 150);
            $t->string('kind', 20); // seta | employer | other
            $t->string('seta_code', 20)->nullable();
            $t->string('template', 30)->default('generic'); // claim layout
            $t->string('contact_email', 190)->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'name']);
        });

        // A signed grant agreement (MoA) for learners in one cohort.
        Schema::create('grant_agreements', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('funder_id')->constrained()->restrictOnDelete();
            $t->foreignUlid('cohort_id')->constrained()->restrictOnDelete();
            $t->string('reference', 80);
            $t->unsignedInteger('learners');
            $t->unsignedBigInteger('amount_per_learner_cents');
            $t->string('funding_kind', 10)->default('full'); // full | co
            $t->unsignedBigInteger('disability_allowance_cents')->nullable();
            $t->date('starts_on');
            $t->date('ends_on');
            $t->foreignUlid('moa_file_id')->nullable()->constrained('files')->nullOnDelete();
            $t->string('status', 12)->default('active'); // active | closed
            $t->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['tenant_id', 'funder_id', 'reference']);
            $t->index(['tenant_id', 'cohort_id']);
        });

        // The learners the agreement funds. A replaced learner stays, marked removed.
        Schema::create('grant_learners', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('agreement_id')->constrained('grant_agreements')->cascadeOnDelete();
            $t->foreignUlid('enrolment_id')->constrained()->restrictOnDelete();
            $t->boolean('disabled')->default(false); // claims the disability allowance
            $t->string('status', 10)->default('active'); // active | removed
            $t->string('removed_reason', 300)->nullable();
            $t->timestamp('removed_at')->nullable();
            $t->timestamps();
            $t->unique(['agreement_id', 'enrolment_id']);
        });

        // Payment stages: a share of the per-learner amount, paid once its milestones are met.
        Schema::create('grant_tranches', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('agreement_id')->constrained('grant_agreements')->cascadeOnDelete();
            $t->unsignedSmallInteger('number');
            $t->decimal('share_pct', 5, 2);
            $t->json('milestones'); // [{key, months?}]
            $t->json('evidence'); // [{title, file_id}] for items the platform doesn't hold
            $t->date('due_on')->nullable();
            $t->timestamps();
            $t->unique(['agreement_id', 'number']);
        });

        foreach (['funders', 'grant_agreements', 'grant_learners', 'grant_tranches'] as $table) {
            Rls::protect($table);
        }
    }

    public function down(): void
    {
        foreach (['grant_tranches', 'grant_learners', 'grant_agreements', 'funders'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
