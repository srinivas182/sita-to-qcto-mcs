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
        // Platform-wide settings, one row per group (e.g. "company").
        Schema::create('platform_settings', function (Blueprint $table): void {
            $table->string('key', 60)->primary();
            $table->jsonb('value');
            $table->ulid('updated_by')->nullable();
            $table->timestampsTz();
        });
        Rls::protectWith('platform_settings', "current_setting('app.context', true) = 'platform'");

        // External services set up by the Super Admin. One row per driver.
        Schema::create('integrations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('type', 20);
            $table->string('driver', 40)->unique();
            $table->text('credentials')->nullable();      // encrypted JSON
            $table->jsonb('settings')->nullable();        // non-secret values
            $table->string('mode', 10)->default('live');  // sandbox | live
            $table->boolean('enabled')->default(false);
            $table->unsignedSmallInteger('priority')->default(100);
            $table->timestampTz('last_tested_at')->nullable();
            $table->boolean('last_test_ok')->nullable();
            $table->string('last_test_message', 500)->nullable();
            $table->ulid('updated_by')->nullable();
            $table->timestampsTz();

            $table->index(['type', 'enabled', 'priority']);
        });
        Rls::protectWith('integrations', "current_setting('app.context', true) = 'platform'");
    }

    public function down(): void
    {
        Schema::dropIfExists('integrations');
        Schema::dropIfExists('platform_settings');
    }
};
