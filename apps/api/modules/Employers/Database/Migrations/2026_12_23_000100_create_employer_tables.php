<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Support\Database\Rls;

/**
 * Host employers, their workplace sites (with SETA workplace approval), the mentors
 * who guide learners there, and the people who act for the employer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employers', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $t->string('legal_name', 200);
            $t->string('trading_name', 200)->nullable();
            $t->string('registration_number', 20)->nullable(); // CIPC
            $t->string('sdl_number', 10)->nullable(); // Skills Development Levy reference, L + 9 digits
            $t->string('seta', 40)->nullable();
            $t->string('bbbee_level', 20)->nullable(); // 1–8, non_compliant, eme, qse
            $t->string('sector', 120)->nullable();
            $t->string('contact_name', 150)->nullable();
            $t->string('contact_email', 200)->nullable();
            $t->string('contact_phone', 20)->nullable();
            $t->string('status', 20)->default('active');
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'registration_number']);
            $t->index(['tenant_id', 'legal_name']);
        });
        Rls::protect('employers');

        Schema::create('employer_sites', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $t->foreignUlid('employer_id')->constrained('employers')->cascadeOnDelete();
            $t->string('name', 150);
            $t->string('address_line1', 200)->nullable();
            $t->string('city', 100)->nullable();
            $t->string('province', 60)->nullable();
            $t->string('postal_code', 10)->nullable();
            $t->decimal('latitude', 9, 6)->nullable();
            $t->decimal('longitude', 9, 6)->nullable();
            // SETA workplace approval
            $t->string('approval_number', 60)->nullable();
            $t->string('approval_body', 40)->nullable();
            $t->date('approved_on')->nullable();
            $t->date('approval_expires_on')->nullable();
            $t->jsonb('approved_qualification_ids')->nullable();
            $t->ulid('approval_file_id')->nullable();
            // Learners per mentor: default for the site, and per qualification when it differs.
            $t->unsignedSmallInteger('mentor_ratio')->default(10);
            $t->jsonb('ratio_overrides')->nullable();
            $t->string('status', 20)->default('active');
            $t->unsignedSmallInteger('alerted_for')->nullable();
            $t->timestamps();
            $t->unique(['employer_id', 'name']);
        });
        Rls::protect('employer_sites');

        Schema::create('employer_mentors', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $t->foreignUlid('employer_id')->constrained('employers')->cascadeOnDelete();
            $t->foreignUlid('user_id')->nullable()->constrained('users')->nullOnDelete(); // when they sign in
            $t->string('name', 150);
            $t->string('email', 200)->nullable();
            $t->string('phone', 20)->nullable();
            $t->string('designation', 150); // job title
            $t->unsignedSmallInteger('years_experience');
            $t->string('highest_qualification', 200)->nullable();
            $t->unsignedSmallInteger('qualification_nqf_level')->nullable();
            $t->jsonb('site_ids');
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Rls::protect('employer_mentors');

        // People who act for an employer (host-employer role): they will only see this employer's learners.
        Schema::create('employer_members', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $t->foreignUlid('employer_id')->constrained('employers')->cascadeOnDelete();
            $t->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $t->timestamps();
            $t->unique(['employer_id', 'user_id']);
        });
        Rls::protect('employer_members');
    }

    public function down(): void
    {
        foreach (['employer_members', 'employer_mentors', 'employer_sites', 'employers'] as $table) {
            Rls::unprotect($table);
            Schema::dropIfExists($table);
        }
    }
};
