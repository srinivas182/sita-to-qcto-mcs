<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Support\Database\Rls;

/** A learner's week at a placement: the mentor signs it (over the entries' hashes) or sends it back. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logbook_weeks', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $t->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $t->foreignUlid('learner_placement_id')->constrained('learner_placements')->cascadeOnDelete();
            $t->date('week_start');
            $t->string('status', 12)->default('open'); // open, signed, returned
            $t->foreignUlid('signed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignUlid('mentor_id')->nullable()->constrained('employer_mentors')->nullOnDelete();
            $t->timestampTz('signed_at')->nullable();
            $t->string('signature', 64)->nullable(); // sha256 over the signed entries' hashes
            $t->text('comment')->nullable();
            $t->timestampTz('reminded_at')->nullable();
            $t->timestampsTz();
            $t->unique(['tenant_id', 'user_id', 'learner_placement_id', 'week_start']);
            $t->index(['tenant_id', 'status', 'week_start']);
        });
        Rls::protect('logbook_weeks');
    }

    public function down(): void
    {
        Schema::dropIfExists('logbook_weeks');
    }
};
