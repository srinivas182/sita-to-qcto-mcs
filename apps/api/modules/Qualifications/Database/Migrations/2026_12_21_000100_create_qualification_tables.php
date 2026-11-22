<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Support\Database\Rls;

/**
 * The shared qualification catalogue (platform-owned, read by every provider once
 * published), what each provider offers, centre scope per qualification, and
 * providers' suggestions for the catalogue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qualifications', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            // occupational_certificate | part_qualification | skills_programme | trade | legacy_qualification | legacy_skills_programme
            $t->string('type', 40);
            $t->string('saqa_id', 12)->nullable()->unique();
            $t->string('curriculum_code', 20)->nullable()->unique();
            $t->string('title', 300);
            $t->unsignedSmallInteger('nqf_level');
            $t->unsignedSmallInteger('credits');
            $t->string('quality_partner', 200)->nullable(); // e.g. CETA, NAMB (QCTO quality partner or legacy ETQA)
            $t->string('assessment_partner', 200)->nullable(); // AQP / trade test body
            $t->boolean('eisa')->default(false);
            $t->string('status', 20)->default('draft'); // draft | published | withdrawn
            $t->date('registration_end')->nullable();
            $t->date('last_enrolment_on')->nullable();
            $t->date('last_achievement_on')->nullable();
            $t->jsonb('successor_ids')->nullable(); // legacy → occupational replacements
            $t->jsonb('credit_rules')->nullable(); // e.g. {"fundamental":59,"core":21,"elective_min":40}
            $t->string('source_url', 500)->nullable();
            $t->text('notes')->nullable();
            $t->timestamp('published_at')->nullable();
            $t->ulid('published_by')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->index(['status', 'type']);
        });
        DB::statement('CREATE INDEX qualifications_search ON qualifications USING gin (to_tsvector(\'simple\', title))');
        Rls::protectCatalogue('qualifications', "status = 'published'");

        Schema::create('qualification_modules', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('qualification_id')->constrained('qualifications')->cascadeOnDelete();
            // KM / PM / WM (occupational) or US (a legacy unit standard)
            $t->string('kind', 2);
            // occupational: compulsory | elective; legacy: fundamental | core | elective
            $t->string('group', 20)->default('compulsory');
            $t->string('code', 40);
            $t->string('title', 400);
            $t->unsignedSmallInteger('nqf_level')->nullable();
            $t->unsignedSmallInteger('credits');
            $t->unsignedSmallInteger('sort')->default(0);
            $t->text('workplace_criteria')->nullable();
            $t->timestamps();
            $t->unique(['qualification_id', 'code']);
        });
        Rls::protectCatalogue('qualification_modules', "EXISTS (SELECT 1 FROM qualifications q WHERE q.id = qualification_id AND q.status = 'published')");

        Schema::create('tenant_qualifications', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $t->foreignUlid('qualification_id')->constrained('qualifications')->restrictOnDelete();
            $t->boolean('active')->default(true);
            $t->jsonb('modes')->nullable(); // contact | blended | online | workplace
            $t->unsignedInteger('price_cents')->nullable();
            $t->text('notes')->nullable();
            $t->unsignedSmallInteger('alerted_for')->nullable(); // last enrolment-closing alert (180/90/30/0 days)
            $t->timestamps();
            $t->unique(['tenant_id', 'qualification_id']);
        });
        Rls::protect('tenant_qualifications');

        Schema::create('centre_qualifications', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $t->foreignUlid('centre_id')->constrained('centres')->cascadeOnDelete();
            $t->foreignUlid('qualification_id')->constrained('qualifications')->restrictOnDelete();
            $t->string('accreditation_number', 40)->nullable(); // when it differs from the centre's
            $t->date('expires_on')->nullable();
            $t->timestamps();
            $t->unique(['centre_id', 'qualification_id']);
        });
        Rls::protect('centre_qualifications');

        Schema::create('catalogue_suggestions', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $t->foreignUlid('qualification_id')->nullable()->constrained('qualifications')->nullOnDelete();
            $t->string('kind', 20); // new | correction
            $t->string('saqa_id', 12)->nullable();
            $t->string('title', 300);
            $t->text('details');
            $t->ulid('file_id')->nullable(); // the curriculum document
            $t->string('status', 20)->default('open'); // open | accepted | declined
            $t->text('response')->nullable();
            $t->ulid('submitted_by');
            $t->ulid('decided_by')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->timestamps();
            $t->index(['status', 'created_at']);
        });
        Rls::protect('catalogue_suggestions');
    }

    public function down(): void
    {
        foreach (['catalogue_suggestions', 'centre_qualifications', 'tenant_qualifications', 'qualification_modules', 'qualifications'] as $table) {
            Rls::unprotect($table);
            Schema::dropIfExists($table);
        }
    }
};
