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
        // A learner's document: a photo or scan, what it is, who certified it and when,
        // the automatic checks and the staff decision.
        Schema::create('learner_documents', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('file_id')->constrained('files')->restrictOnDelete();
            $table->string('type', 30);
            $table->boolean('certified')->default(false);
            $table->date('certified_on')->nullable();
            $table->string('certified_by', 150)->nullable();
            $table->json('checks')->nullable();
            $table->string('status', 10)->default('pending'); // pending, accepted, rejected
            $table->string('reason', 1000)->nullable();
            $table->foreignUlid('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'user_id', 'type']);
        });

        Schema::create('enrolment_settings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('document_rules')->nullable(); // per type: certified copy needed, how old it may be
            $table->timestamps();
        });

        Schema::table('applications', function (Blueprint $table): void {
            $table->timestamp('documents_requested_at')->nullable();
        });

        Rls::protect('learner_documents');
        Rls::protect('enrolment_settings');
    }

    public function down(): void
    {
        Schema::table('applications', fn (Blueprint $t) => $t->dropColumn('documents_requested_at'));
        Schema::dropIfExists('enrolment_settings');
        Schema::dropIfExists('learner_documents');
    }
};
