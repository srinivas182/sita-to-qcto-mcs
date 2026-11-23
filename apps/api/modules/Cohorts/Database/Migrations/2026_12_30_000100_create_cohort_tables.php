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
        Schema::create('cohorts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->foreignUlid('qualification_id')->constrained('qualifications')->restrictOnDelete();
            $table->foreignUlid('centre_id')->constrained('centres')->restrictOnDelete();
            $table->string('mode', 12);
            $table->date('intake_on');
            $table->date('planned_end_on');
            $table->unsignedSmallInteger('capacity');
            $table->string('status', 12)->default('draft'); // draft, open, closed, cancelled
            $table->text('notes')->nullable();
            $table->json('readiness')->nullable(); // the check as it stood when opened
            $table->json('watch_fails')->nullable(); // failures already reported while open
            $table->timestamp('opened_at')->nullable();
            $table->foreignUlid('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'centre_id']);
        });

        Schema::create('cohort_staff', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('cohort_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 12); // facilitator, assessor, moderator
            $table->timestamps();
            $table->unique(['cohort_id', 'user_id', 'role']);
        });

        Schema::create('cohort_placements', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('cohort_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('employer_id')->constrained('employers')->restrictOnDelete();
            $table->foreignUlid('site_id')->constrained('employer_sites')->restrictOnDelete();
            $table->foreignUlid('mentor_id')->nullable()->constrained('employer_mentors')->nullOnDelete();
            $table->unsignedSmallInteger('planned_learners');
            $table->timestamps();
            $table->unique(['cohort_id', 'site_id']);
            $table->index(['tenant_id', 'site_id']);
        });

        foreach (['cohorts', 'cohort_staff', 'cohort_placements'] as $t) {
            Rls::protect($t);
        }
    }

    public function down(): void
    {
        foreach (['cohort_placements', 'cohort_staff', 'cohorts'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
