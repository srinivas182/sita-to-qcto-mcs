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
        // A provider's agreement wording. Published versions never change: editing one
        // starts the next version, and agreements keep the version they were sent with.
        Schema::create('agreement_templates', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->ulid('family_id');
            $table->unsignedInteger('version');
            $table->string('kind', 20); // workplace, employer_mou, learner
            $table->string('name', 150);
            $table->text('body');
            $table->json('parties'); // roles that sign, in order
            $table->boolean('advanced_signature')->default(false);
            $table->string('status', 12)->default('draft'); // draft, published, retired
            $table->timestamp('published_at')->nullable();
            $table->foreignUlid('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'family_id', 'version']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('agreements', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('template_id')->constrained('agreement_templates')->restrictOnDelete();
            $table->string('kind', 20);
            $table->string('title', 200);
            $table->text('body'); // frozen at sending: what everyone signs
            $table->char('body_sha256', 64);
            $table->json('merge');
            $table->string('signature_level', 10)->default('simple'); // simple, advanced
            $table->string('status', 12)->default('draft'); // draft, sent, completed, declined, cancelled, expired
            $table->string('provider_ref', 100)->nullable(); // the advanced provider's package
            $table->foreignUlid('employer_id')->nullable()->constrained('employers')->nullOnDelete();
            $table->foreignUlid('site_id')->nullable()->constrained('employer_sites')->nullOnDelete();
            $table->foreignUlid('qualification_id')->nullable()->constrained('qualifications')->nullOnDelete();
            $table->foreignUlid('learner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('verify_code', 12)->unique();
            $table->foreignUlid('sealed_file_id')->nullable()->constrained('files')->nullOnDelete();
            $table->char('sealed_sha256', 64)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'employer_id']);
        });

        Schema::create('agreement_signers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('agreement_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20); // learner, employer, provider
            $table->unsignedSmallInteger('sort')->default(0);
            $table->string('name', 150);
            $table->string('email', 200)->nullable();
            $table->string('phone', 20)->nullable();
            $table->foreignUlid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->string('status', 10)->default('pending'); // pending, signed, declined
            $table->string('code_hash', 128)->nullable();
            $table->timestamp('code_expires_at')->nullable();
            $table->timestamp('code_sent_at')->nullable();
            $table->unsignedSmallInteger('code_attempts')->default(0);
            $table->unsignedSmallInteger('codes_sent')->default(0);
            $table->unsignedSmallInteger('reminders_sent')->default(0);
            $table->timestamp('signed_at')->nullable();
            $table->string('signed_name', 150)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 300)->nullable();
            $table->json('evidence')->nullable();
            $table->string('decline_reason', 1000)->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'agreement_id']);
        });

        Schema::create('agreement_settings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('advanced_by_default')->default(false);
            $table->unsignedSmallInteger('valid_days')->default(30);
            $table->timestamps();
        });

        foreach (['agreement_templates', 'agreements', 'agreement_signers', 'agreement_settings'] as $t) {
            Rls::protect($t);
        }
    }

    public function down(): void
    {
        foreach (['agreement_settings', 'agreement_signers', 'agreements', 'agreement_templates'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
