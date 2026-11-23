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
        // Formative tasks: a quiz, evidence from the phone, or both; marked with feedback.
        Schema::create('tasks', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('qualification_id')->constrained()->restrictOnDelete();
            $t->foreignUlid('module_id')->nullable()->constrained('qualification_modules')->nullOnDelete();
            $t->string('title', 150);
            $t->text('instructions')->nullable();
            $t->json('questions'); // [{id, type: single|multi|short, text, options, correct, marks}]
            $t->boolean('evidence')->default(false); // photos, video or a PDF from the learner
            $t->string('evidence_hint', 300)->nullable();
            $t->unsignedTinyInteger('pass_pct')->default(70);
            $t->unsignedTinyInteger('attempts')->default(3);
            $t->string('status', 10)->default('draft'); // draft, published
            $t->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['tenant_id', 'qualification_id', 'status']);
        });

        Schema::create('task_attempts', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('task_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('enrolment_id')->constrained()->cascadeOnDelete();
            $t->unsignedTinyInteger('number');
            $t->json('answers');
            $t->json('file_ids');
            $t->unsignedSmallInteger('auto_marks')->default(0);
            $t->unsignedSmallInteger('marks')->nullable();
            $t->unsignedSmallInteger('out_of');
            $t->unsignedTinyInteger('score_pct')->nullable();
            $t->string('status', 10)->default('submitted'); // submitted, passed, not_yet
            $t->text('feedback')->nullable();
            $t->foreignUlid('marked_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('marked_at')->nullable();
            $t->timestamp('submitted_at');
            $t->boolean('offline')->default(false);
            $t->timestamps();
            $t->unique(['task_id', 'user_id', 'number']);
            $t->index(['tenant_id', 'status']);
        });

        foreach (['tasks', 'task_attempts'] as $table) {
            Rls::protect($table);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('task_attempts');
        Schema::dropIfExists('tasks');
    }
};
