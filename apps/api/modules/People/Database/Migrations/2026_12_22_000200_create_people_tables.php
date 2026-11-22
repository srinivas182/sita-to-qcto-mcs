<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Support\Database\Rls;

/**
 * What a provider keeps about the people it works with: profiles (with optional,
 * consented, encrypted equity data for SETA reporting) and the staff credential register.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('person_profiles', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $t->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $t->string('address_line1', 200)->nullable();
            $t->string('address_line2', 200)->nullable();
            $t->string('city', 100)->nullable();
            $t->string('province', 60)->nullable();
            $t->string('postal_code', 10)->nullable();
            $t->string('home_language', 40)->nullable();
            $t->string('emergency_name', 150)->nullable();
            $t->string('emergency_phone', 20)->nullable();
            $t->string('emergency_relationship', 60)->nullable();
            $t->text('equity')->nullable(); // encrypted JSON: gender, race, disability, disability_details
            $t->timestamp('equity_consented_at')->nullable();
            $t->string('equity_consent_version', 20)->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'user_id']);
        });
        Rls::protect('person_profiles');

        Schema::create('staff_credentials', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $t->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            // facilitator | assessor | moderator | rpl_advisor | mentor | subject_expert
            $t->string('role', 30);
            $t->string('body', 120); // QCTO, CETA, ETDP SETA…
            $t->string('registration_number', 60);
            $t->jsonb('qualification_ids'); // catalogue entries in scope
            $t->date('starts_on')->nullable();
            $t->date('expires_on')->nullable();
            $t->ulid('file_id')->nullable(); // the registration letter
            $t->string('status', 20)->default('pending'); // pending | verified | rejected
            $t->ulid('verified_by')->nullable();
            $t->timestamp('verified_at')->nullable();
            $t->text('notes')->nullable();
            $t->unsignedSmallInteger('alerted_for')->nullable();
            $t->ulid('added_by')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'user_id', 'role']);
            $t->unique(['tenant_id', 'user_id', 'role', 'body', 'registration_number']);
        });
        Rls::protect('staff_credentials');
    }

    public function down(): void
    {
        foreach (['staff_credentials', 'person_profiles'] as $table) {
            Rls::unprotect($table);
            Schema::dropIfExists($table);
        }
    }
};
