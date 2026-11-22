<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Support\Database\Rls;

/**
 * Role assignments (ADR-009). Roles and permissions are defined in config/access.php;
 * only who holds which role, where, is data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_assignments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 40);
            $table->ulid('centre_id')->nullable();
            $table->ulid('granted_by')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('centre_id')->references('id')->on('centres')->cascadeOnDelete();
            $table->index(['tenant_id', 'user_id']);
        });
        DB::statement("CREATE UNIQUE INDEX role_assignments_unique ON role_assignments (tenant_id, user_id, role, coalesce(centre_id, ''))");
        Rls::protect('role_assignments');

        // Platform roles: visible only in the platform context.
        Schema::create('platform_role_assignments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 40);
            $table->ulid('granted_by')->nullable();
            $table->timestampsTz();

            $table->unique(['user_id', 'role']);
        });
        Rls::protectWith('platform_role_assignments', "current_setting('app.context', true) = 'platform'");

        Schema::create('invitations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->string('email', 254)->nullable();
            $table->string('phone_e164', 16)->nullable();
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->string('role', 40);
            $table->ulid('centre_id')->nullable();
            $table->string('token_hash', 64)->unique();
            $table->ulid('invited_by');
            $table->timestampTz('expires_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->ulid('accepted_by')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('centre_id')->references('id')->on('centres')->cascadeOnDelete();
            $table->index(['tenant_id', 'accepted_at']);
        });
        Rls::protect('invitations');

        Schema::create('support_access_grants', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->foreignUlid('granted_to')->constrained('users')->cascadeOnDelete();
            $table->string('reason', 500);
            $table->unsignedSmallInteger('minutes');
            $table->string('status', 16)->default('pending');
            $table->ulid('approved_by')->nullable();
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'status']);
            $table->index(['granted_to', 'status']);
        });
        Rls::protect('support_access_grants');
    }

    public function down(): void
    {
        foreach (['support_access_grants', 'invitations', 'platform_role_assignments', 'role_assignments'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
