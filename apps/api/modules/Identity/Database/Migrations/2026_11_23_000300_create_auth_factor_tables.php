<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Support\Database\Rls;

/**
 * Sign-in factors. Passkeys and MFA belong to the person (global); PIN challenges,
 * devices and tokens are issued within one training provider and are tenant-scoped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('passkeys', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('credential_id', 512)->unique();
            $table->jsonb('source');
            $table->unsignedBigInteger('sign_count')->default(0);
            $table->string('name', 100);
            $table->uuid('aaguid')->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('mfa_factors', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16);
            $table->text('secret');
            $table->jsonb('recovery_codes')->nullable();
            $table->unsignedBigInteger('last_timestep')->nullable();
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['user_id', 'type']);
        });

        Schema::create('otp_challenges', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('user_id')->nullable();
            $table->string('destination', 254)->nullable();
            $table->string('channel', 16);
            $table->string('purpose', 24)->default('sign_in');
            $table->string('code_hash', 64);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'user_id', 'created_at']);
        });
        Rls::protect('otp_challenges');

        Schema::create('devices', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 16);
            $table->string('name', 100);
            $table->string('push_token', 512)->nullable();
            $table->string('public_key', 64);
            $table->string('offline_key_fingerprint', 64);
            $table->timestampTz('offline_until')->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'user_id']);
        });
        Rls::protect('devices');

        Schema::create('refresh_tokens', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->foreignUlid('device_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('used_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->ulid('replaced_by')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
        Rls::protect('refresh_tokens');

        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->ulid('tenant_id');
            $table->ulid('device_id')->nullable()->index();
            $table->ulidMorphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('expires_at')->nullable()->index();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
        Rls::protect('personal_access_tokens');

        Schema::create('consents', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('purpose', 40);
            $table->string('version', 20);
            $table->timestampTz('granted_at');
            $table->timestampTz('withdrawn_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'user_id', 'purpose']);
        });
        Rls::protect('consents');
    }

    public function down(): void
    {
        foreach (['consents', 'personal_access_tokens', 'refresh_tokens', 'devices', 'otp_challenges', 'mfa_factors', 'passkeys'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
