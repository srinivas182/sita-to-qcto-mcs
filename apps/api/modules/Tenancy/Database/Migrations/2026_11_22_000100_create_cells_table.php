<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cells are independent stacks (database, cache, workers) that each host a group of
 * tenants. This table lives in the global layer and is not tenant-scoped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cells', function (Blueprint $table): void {
            $table->string('id', 32)->primary();
            $table->string('name', 120);
            $table->string('region', 64);
            $table->string('status', 16)->default('active')->index();
            $table->unsignedInteger('capacity_tenants')->default(500);
            $table->boolean('dedicated')->default(false);
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cells');
    }
};
