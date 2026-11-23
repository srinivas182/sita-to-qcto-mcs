<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Support\Database\Rls;

/**
 * The EISA calendar (kept by the platform from the quality partners' published plans, with
 * providers' own dates or corrections), and each learner's EISA registration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eisa_calendar', function (Blueprint $t): void { // platform-wide: no tenant
            $t->ulid('id')->primary();
            $t->foreignUlid('qualification_id')->constrained('qualifications')->cascadeOnDelete();
            $t->string('quality_partner', 60)->nullable();
            $t->date('eisa_on');
            $t->date('closes_on');
            $t->string('source', 300)->nullable();
            $t->timestampsTz();
            $t->unique(['qualification_id', 'eisa_on']);
        });
        Schema::create('eisa_dates', function (Blueprint $t): void { // a provider's own date or correction
            $t->ulid('id')->primary();
            $t->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $t->foreignUlid('qualification_id')->constrained('qualifications')->cascadeOnDelete();
            $t->foreignUlid('replaces_id')->nullable()->constrained('eisa_calendar')->nullOnDelete();
            $t->date('eisa_on')->nullable(); // null with replaces_id: that date does not apply to us
            $t->date('closes_on')->nullable();
            $t->string('source', 300);
            $t->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
        });
        Schema::create('eisa_registrations', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $t->foreignUlid('enrolment_id')->constrained('enrolments')->cascadeOnDelete();
            $t->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $t->foreignUlid('cohort_id')->constrained('cohorts')->cascadeOnDelete();
            $t->date('eisa_on');
            $t->date('closes_on');
            $t->string('assessment_centre', 60)->nullable();
            $t->string('assessment_centre_name', 200)->nullable();
            $t->string('flc_code', 2)->nullable();
            $t->string('flc_number', 60)->nullable();
            $t->ulid('flc_file_id')->nullable();
            $t->timestampTz('declared_at')->nullable();
            $t->string('declared_name', 200)->nullable();
            $t->string('declaration_version', 10)->nullable();
            $t->string('status', 12)->default('draft'); // draft, registered, withdrawn
            $t->foreignUlid('registered_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('registered_at')->nullable();
            $t->timestampsTz();
            $t->index(['tenant_id', 'cohort_id', 'status']);
        });
        Rls::protect('eisa_dates');
        Rls::protect('eisa_registrations');
        Schema::table('cohorts', function (Blueprint $t): void {
            $t->smallInteger('eisa_alerted_for')->nullable(); // last closing-date alert (30, 14, 3 days)
        });
    }

    public function down(): void
    {
        Schema::table('cohorts', fn (Blueprint $t) => $t->dropColumn('eisa_alerted_for'));
        Schema::dropIfExists('eisa_registrations');
        Schema::dropIfExists('eisa_dates');
        Schema::dropIfExists('eisa_calendar');
    }
};
