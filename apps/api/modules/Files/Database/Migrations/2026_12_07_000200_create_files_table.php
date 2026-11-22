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
        Schema::create('files', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->nullable();          // null: platform-owned
            $table->ulid('owner_id')->nullable();
            $table->string('purpose', 40);
            $table->string('original_name', 255);
            $table->string('mime', 100);
            $table->unsignedBigInteger('size');
            $table->string('sha256', 64)->nullable();
            $table->string('store_driver', 40);
            $table->ulid('integration_id')->nullable();     // null: the default store
            $table->string('path', 500);
            $table->string('status', 20);                   // pending, processing, ready, rejected, failed
            $table->string('status_reason', 300)->nullable();
            $table->jsonb('variants')->nullable();
            $table->timestampTz('uploaded_at')->nullable();
            $table->timestampTz('scanned_at')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'purpose', 'created_at']);
            $table->index('integration_id');
        });
        // Tenant rows: that tenant only. Platform rows (null tenant): platform context only.
        Rls::protect('files');
    }

    public function down(): void
    {
        Schema::dropIfExists('files');
    }
};
