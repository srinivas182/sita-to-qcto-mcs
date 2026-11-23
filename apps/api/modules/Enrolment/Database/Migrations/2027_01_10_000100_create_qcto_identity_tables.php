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
        // The QCTO learner enrolment file asks for more than Sprint 3's profile held.
        Schema::table('person_profiles', function (Blueprint $t): void {
            $t->string('title', 6)->nullable();
            $t->string('middle_name', 100)->nullable();
            $t->string('nationality', 4)->nullable();      // QCTO nationality code (SA, ZIM, …)
            $t->string('citizen_status', 3)->nullable();   // SA, PR, D, O
            $t->string('socioeconomic_status', 3)->nullable(); // 01 employed, 02 unemployed, …
            $t->boolean('postal_same')->default(true);
            $t->string('postal_line1', 200)->nullable();
            $t->string('postal_line2', 200)->nullable();
            $t->string('postal_code_postal', 4)->nullable();
            $t->string('statssa_area_code', 20)->nullable();
        });

        Schema::table('enrolment_settings', function (Blueprint $t): void {
            $t->unsignedSmallInteger('qcto_days')->default(21);
            $t->boolean('qcto_working_days')->default(false);
            $t->unsignedSmallInteger('applicant_retention_months')->default(12);
            $t->boolean('identity_auto')->default(false);
        });

        Schema::table('cohorts', function (Blueprint $t): void {
            $t->smallInteger('qcto_alerted_for')->nullable();
        });

        Schema::create('qcto_submissions', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('cohort_id')->constrained()->cascadeOnDelete();
            $t->string('stage', 20)->default('enrolment'); // enrolment; eisa readiness comes later
            $t->json('learner_ids');
            $t->unsignedInteger('rows');
            $t->date('sent_on');
            $t->string('note', 500)->nullable();
            $t->foreignUlid('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['tenant_id', 'cohort_id']);
        });

        Schema::create('identity_checks', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $t->string('status', 10)->default('queued'); // queued, verified, mismatch, not_found, deceased, failed
            $t->string('driver', 40)->nullable();
            $t->json('result')->nullable(); // names, date of birth, alive; never the photo
            $t->string('reason', 300)->nullable();
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->unsignedInteger('cost_cents')->nullable();
            $t->foreignUlid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('checked_at')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'status']);
            $t->index(['tenant_id', 'user_id']);
        });

        foreach (['qcto_submissions', 'identity_checks'] as $table) {
            Rls::protect($table);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_checks');
        Schema::dropIfExists('qcto_submissions');
        Schema::table('cohorts', fn (Blueprint $t) => $t->dropColumn('qcto_alerted_for'));
        Schema::table('enrolment_settings', fn (Blueprint $t) => $t->dropColumn(['qcto_days', 'qcto_working_days', 'applicant_retention_months', 'identity_auto']));
        Schema::table('person_profiles', fn (Blueprint $t) => $t->dropColumn(['title', 'middle_name', 'nationality', 'citizen_status', 'socioeconomic_status',
            'postal_same', 'postal_line1', 'postal_line2', 'postal_code_postal', 'statssa_area_code']));
    }
};
