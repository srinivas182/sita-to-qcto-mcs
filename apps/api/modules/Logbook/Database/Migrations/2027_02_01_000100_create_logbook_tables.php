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
        // The provider's rules for a work experience module: its activities and the hours needed.
        Schema::create('logbook_rules', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('module_id')->constrained('qualification_modules')->cascadeOnDelete();
            $t->json('activities'); // [{key, title, photo: bool}]
            $t->unsignedSmallInteger('hours_needed');
            $t->string('evidence_note', 500)->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'module_id']);
        });

        // Which learner is at which workplace, with which mentor, from when.
        Schema::create('learner_placements', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('enrolment_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('cohort_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('site_id')->constrained('employer_sites')->restrictOnDelete();
            $t->foreignUlid('mentor_id')->nullable()->constrained('employer_mentors')->nullOnDelete();
            $t->date('starts_on');
            $t->date('ends_on')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'user_id']);
            $t->index(['tenant_id', 'mentor_id']);
        });

        Schema::create('logbook_entries', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('learner_placement_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('module_id')->constrained('qualification_modules')->restrictOnDelete();
            $t->string('activity_key', 40);
            $t->date('work_date');
            $t->date('week_start'); // Monday of the week, for sign-off
            $t->unsignedSmallInteger('minutes');
            $t->text('note')->nullable();
            $t->json('file_ids');
            $t->json('photo_hashes');
            $t->unsignedInteger('distance_m')->nullable();
            $t->boolean('location_ok')->nullable();
            $t->boolean('offline')->default(false);
            $t->timestampTz('device_time');
            $t->string('device_time_text', 40); // exactly as hashed on the phone
            $t->uuid('client_id');
            $t->unsignedInteger('seq');
            $t->char('prev_hash', 64)->nullable();
            $t->char('hash', 64);
            $t->boolean('rechained')->default(false); // the phone's chain was behind the server's; the server re-linked it
            $t->string('status', 10)->default('submitted'); // submitted, signed, returned, corrected
            $t->ulid('replaces_id')->nullable(); // the entry this one corrects
            $t->string('comment', 500)->nullable(); // the mentor's, when sent back
            $t->timestamps();
            $t->unique(['tenant_id', 'user_id', 'client_id']);
            $t->unique(['tenant_id', 'user_id', 'seq']);
            $t->index(['tenant_id', 'learner_placement_id', 'week_start']);
        });

        foreach (['logbook_rules', 'learner_placements', 'logbook_entries'] as $table) {
            Rls::protect($table);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('logbook_entries');
        Schema::dropIfExists('learner_placements');
        Schema::dropIfExists('logbook_rules');
    }
};
