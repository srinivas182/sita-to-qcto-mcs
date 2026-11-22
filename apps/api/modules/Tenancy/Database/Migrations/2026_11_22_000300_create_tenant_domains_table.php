<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Support\Database\Rls;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_domains', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->string('hostname', 253)->unique();
            $table->string('type', 16);
            $table->string('verification_token', 64);
            $table->string('dns_status', 16)->default('pending');
            $table->string('ssl_status', 16)->default('pending');
            $table->string('provider_ref', 100)->nullable();
            $table->jsonb('last_check')->nullable();
            $table->timestampTz('last_checked_at')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });

        // At most one primary domain per tenant.
        DB::statement('CREATE UNIQUE INDEX tenant_domains_one_primary ON tenant_domains (tenant_id) WHERE is_primary');

        Rls::protect('tenant_domains');
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_domains');
    }
};
