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
        Schema::create('tenants', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('cell_id', 32)->index();
            $table->string('code', 30)->unique();
            $table->string('legal_name', 200);
            $table->string('trading_name', 200);
            $table->string('registration_number', 40)->nullable();
            $table->string('status', 16)->default('pending')->index();
            $table->string('package_code', 40)->nullable();
            $table->jsonb('branding')->nullable();
            $table->ulid('created_by')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('suspended_at')->nullable();
            $table->string('suspension_reason', 500)->nullable();
            $table->timestampsTz();

            $table->foreign('cell_id')->references('id')->on('cells');
        });

        // A tenant session can see only its own tenant row.
        Rls::protect('tenants', 'id');
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
