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
        Schema::create('rooms', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('centre_id')->constrained()->cascadeOnDelete();
            $t->string('name', 80);
            $t->unsignedSmallInteger('capacity')->nullable();
            $t->string('kind', 12)->default('classroom'); // classroom, workshop, computer_lab, outdoor
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['centre_id', 'name']);
        });

        // "sessions" is Laravel's web session table, so class sessions have their own name.
        Schema::create('class_sessions', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('cohort_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('module_id')->nullable()->constrained('qualification_modules')->nullOnDelete();
            $t->string('kind', 10); // knowledge, practical
            $t->string('title', 150)->nullable();
            $t->timestampTz('starts_at');
            $t->timestampTz('ends_at');
            $t->foreignUlid('room_id')->nullable()->constrained()->nullOnDelete();
            $t->string('venue', 150)->nullable(); // when not in one of the centre's rooms
            $t->foreignUlid('facilitator_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('status', 10)->default('scheduled'); // scheduled, cancelled
            $t->string('cancel_reason', 300)->nullable();
            $t->ulid('series_id')->nullable(); // sessions created together as a weekly pattern
            $t->string('notes', 500)->nullable();
            $t->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['tenant_id', 'cohort_id', 'starts_at']);
            $t->index(['tenant_id', 'facilitator_id', 'starts_at']);
            $t->index(['tenant_id', 'room_id', 'starts_at']);
            $t->index(['series_id']);
        });

        foreach (['rooms', 'class_sessions'] as $table) {
            Rls::protect($table);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('class_sessions');
        Schema::dropIfExists('rooms');
    }
};
