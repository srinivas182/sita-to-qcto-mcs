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
        Schema::create('offers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('application_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->string('status', 10)->default('open'); // open, accepted, declined, lapsed, withdrawn
            $table->string('funding', 20)->nullable();
            $table->timestamp('expires_at');
            $table->json('guardian')->nullable(); // name, phone, relationship for a learner under 18
            $table->foreignUlid('agreement_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('responded_at')->nullable();
            $table->string('reason', 500)->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
        });

        // The provider's consent wording, by purpose, with versions; and who accepted which.
        Schema::create('consent_texts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('purpose', 30);
            $table->unsignedInteger('version');
            $table->text('text');
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'purpose', 'version']);
        });
        // Consent records are Sprint 1's POPIA consents; they now also keep which wording
        // was accepted, who gave it (a parent or guardian) and where.
        Schema::table('consents', function (Blueprint $table): void {
            $table->foreignUlid('consent_text_id')->nullable()->constrained()->nullOnDelete();
            $table->string('given_by', 150)->nullable();
            $table->string('channel', 6)->nullable();
        });

        Schema::create('enrolments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('cohort_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('application_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('agreement_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 10)->default('active'); // active, suspended, withdrawn, completed
            $table->string('funding', 20)->nullable();
            $table->date('enrolled_on');
            $table->date('ended_on')->nullable();
            $table->string('reason', 500)->nullable();
            $table->timestamps();
            $table->unique(['cohort_id', 'user_id']);
            $table->index(['tenant_id', 'user_id', 'status']);
        });

        foreach (['offers', 'consent_texts', 'enrolments'] as $t) {
            Rls::protect($t);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('enrolments');
        Schema::table('consents', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('consent_text_id');
            $table->dropColumn(['given_by', 'channel']);
        });
        Schema::dropIfExists('consent_texts');
        Schema::dropIfExists('offers');
    }
};
