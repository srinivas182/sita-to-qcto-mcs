<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Support\Database\Rls;

/**
 * Centres (delivery sites) of a training provider. Each centre carries its own QCTO
 * accreditation. The full centre module arrives in Sprint 2; this is the base table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('centres', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->string('name', 200);
            $table->string('accreditation_number', 60)->nullable();
            $table->date('accreditation_expires_on')->nullable();
            $table->string('status', 16)->default('active');
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'status']);
            $table->unique(['tenant_id', 'name']);
        });

        Rls::protect('centres');
    }

    public function down(): void
    {
        Schema::dropIfExists('centres');
    }
};
