<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Support\Database\Rls;

/** Roles a provider builds from the permission catalogue (never beyond its own permissions). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_roles', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $t->string('key', 40)->unique(); // custom:<ulid>, stored in role_assignments.role
            $t->string('name', 80);
            $t->string('description', 300)->nullable();
            $t->string('scope', 10)->default('tenant'); // tenant | centre
            $t->jsonb('permissions');
            $t->boolean('mfa')->default(true);
            $t->string('based_on', 40)->nullable();
            $t->ulid('created_by')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'name']);
        });
        Rls::protect('custom_roles');

        // An ID number given when inviting (e.g. a bulk import) is applied on acceptance.
        Schema::table('invitations', function (Blueprint $t): void {
            $t->string('id_type', 20)->nullable();
            $t->text('id_number')->nullable(); // encrypted
        });
    }

    public function down(): void
    {
        Schema::table('invitations', fn (Blueprint $t) => $t->dropColumn(['id_type', 'id_number']));
        Rls::unprotect('custom_roles');
        Schema::dropIfExists('custom_roles');
    }
};
