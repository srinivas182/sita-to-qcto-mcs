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
        // One row per attempt to deliver a message through one provider.
        Schema::create('messages', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->nullable();        // null: platform message
            $table->string('channel', 12);                // sms | whatsapp
            $table->string('driver', 40);
            $table->string('purpose', 30);                // otp, billing, notification, test
            $table->string('destination_hash', 64);       // HMAC, for lookups without storing the number
            $table->string('destination_masked', 20);
            $table->string('status', 16);                 // sent, delivered, read, failed, undelivered
            $table->string('provider_message_id', 120)->nullable();
            $table->unsignedInteger('cost_cents')->nullable();
            $table->string('error', 300)->nullable();
            $table->ulid('fallback_of')->nullable();      // the earlier attempt this replaced
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['driver', 'provider_message_id']);
            $table->index(['tenant_id', 'created_at']);
            $table->index(['created_at']);
        });
        Rls::protect('messages');
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
