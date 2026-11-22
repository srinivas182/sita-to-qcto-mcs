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
        Schema::create('user_tenants', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('user_id');
            $table->ulid('centre_id')->nullable();
            $table->string('status', 16)->default('active');
            $table->ulid('invited_by')->nullable();
            $table->timestampTz('joined_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'user_id']);
            $table->index(['user_id', 'status']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('centre_id')->references('id')->on('centres')->nullOnDelete();
        });

        Rls::protect('user_tenants');

        // A tenant session sees only people who belong to it.
        Rls::protectWith('users', "current_setting('app.context', true) = 'platform' OR EXISTS ("
            .'SELECT 1 FROM user_tenants ut WHERE ut.user_id = users.id '
            ."AND ut.tenant_id = nullif(current_setting('app.tenant_id', true), ''))");
    }

    public function down(): void
    {
        Rls::unprotect('users');
        Schema::dropIfExists('user_tenants');
    }
};
