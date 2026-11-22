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
        // Applications from training providers (before a tenant exists). Platform only.
        Schema::create('tenant_applications', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('reference', 20)->unique();
            $table->string('status', 20);                  // draft, submitted, info_requested, approved, declined
            $table->string('token_hash', 64);              // the applicant's resume token
            // Contact (becomes the owner)
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('email', 254);
            $table->string('phone', 20)->nullable();
            $table->string('email_code_hash', 64)->nullable();
            $table->timestampTz('email_code_expires_at')->nullable();
            $table->unsignedTinyInteger('email_code_attempts')->default(0);
            $table->timestampTz('email_verified_at')->nullable();
            // Organisation
            $table->string('legal_name', 200);
            $table->string('trading_name', 200)->nullable();
            $table->string('registration_number', 20)->nullable();
            $table->string('vat_number', 20)->nullable();
            $table->string('website', 200)->nullable();
            // Accreditation and first centre
            $table->string('sdp_number', 40)->nullable();
            $table->date('accreditation_expires_on')->nullable();
            $table->jsonb('scope')->nullable();            // qualification codes
            $table->string('centre_name', 200)->nullable();
            $table->string('address_line1', 200)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('province', 100)->nullable();
            $table->string('postal_code', 10)->nullable();
            // Plan and domain
            $table->string('preferred_code', 30)->nullable();
            $table->ulid('package_id')->nullable();
            $table->string('interval', 10)->default('month');
            // Consent
            $table->timestampTz('consented_at')->nullable();
            $table->string('consent_version', 20)->nullable();
            // Review
            $table->jsonb('auto_checks')->nullable();
            $table->jsonb('checklist')->nullable();
            $table->text('review_notes')->nullable();
            $table->string('decision_reason', 1000)->nullable();
            $table->ulid('reviewed_by')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->ulid('tenant_id')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestampsTz();

            $table->index(['status', 'submitted_at']);
            $table->index('email');
            $table->index('registration_number');
            $table->index('sdp_number');
        });
        Rls::protectWith('tenant_applications', "current_setting('app.context', true) = 'platform'");

        Schema::create('application_files', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('application_id')->constrained('tenant_applications')->cascadeOnDelete();
            $table->ulid('file_id');
            $table->string('kind', 30);                    // accreditation_letter, cipc_certificate, other
            $table->timestampsTz();
        });
        Rls::protectWith('application_files', "current_setting('app.context', true) = 'platform'");

        // Centres become full accredited delivery sites.
        Schema::table('centres', function (Blueprint $table): void {
            $table->string('accreditation_type', 10)->default('sdp');   // sdp | ac
            $table->jsonb('scope')->nullable();
            $table->string('address_line1', 200)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('province', 100)->nullable();
            $table->string('postal_code', 10)->nullable();
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();
            $table->smallInteger('alerted_for')->nullable();             // last expiry alert sent: 90, 60, 30, 0
        });
    }

    public function down(): void
    {
        Schema::table('centres', function (Blueprint $table): void {
            $table->dropColumn(['accreditation_type', 'scope', 'address_line1', 'city', 'province', 'postal_code', 'latitude', 'longitude', 'alerted_for']);
        });
        Schema::dropIfExists('application_files');
        Schema::dropIfExists('tenant_applications');
    }
};
