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
        // A cohort published for applications: dates, places, who may apply, what is asked.
        Schema::create('intakes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('cohort_id')->constrained()->cascadeOnDelete();
            $table->string('slug', 12)->unique();
            $table->string('title', 150);
            $table->text('description')->nullable();
            $table->date('opens_on');
            $table->date('closes_on');
            $table->unsignedSmallInteger('places');
            $table->unsignedSmallInteger('min_age')->nullable();
            $table->unsignedSmallInteger('max_age')->nullable();
            $table->json('document_types')->nullable(); // documents an applicant must give (4b)
            $table->json('questions')->nullable();
            $table->string('status', 10)->default('draft'); // draft, open, closed
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('applications', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('intake_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 12)->unique();
            // submitted, shortlisted, waitlisted, offered, accepted, enrolled, declined, withdrawn
            $table->string('status', 12)->default('submitted');
            $table->json('answers')->nullable();
            $table->string('reason', 1000)->nullable();
            $table->unsignedInteger('waitlist_position')->nullable();
            $table->string('source', 6)->default('web'); // web, app
            $table->timestamp('submitted_at');
            $table->timestamp('decided_at')->nullable();
            $table->foreignUlid('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['intake_id', 'user_id']);
            $table->index(['tenant_id', 'intake_id', 'status']);
            $table->index(['tenant_id', 'user_id']);
        });

        foreach (['intakes', 'applications'] as $t) {
            Rls::protect($t);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('applications');
        Schema::dropIfExists('intakes');
    }
};
