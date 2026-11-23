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
        // A month's attendance register for a cohort: a snapshot, signed in the app, sent to the SETA.
        Schema::create('attendance_registers', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('cohort_id')->constrained()->cascadeOnDelete();
            $t->date('month'); // first day of the month
            $t->string('status', 10)->default('signing'); // signing, complete, submitted
            $t->json('grid'); // sessions and each learner's mark, as at creation
            $t->char('grid_sha256', 64);
            $t->foreignUlid('file_id')->nullable()->constrained('files')->nullOnDelete();
            $t->date('submitted_on')->nullable();
            $t->string('submitted_note', 300)->nullable();
            $t->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('reminded_at')->nullable();
            $t->timestamps();
            $t->unique(['cohort_id', 'month']);
        });

        Schema::create('register_signatures', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('register_id')->constrained('attendance_registers')->cascadeOnDelete();
            $t->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $t->string('role', 12); // learner, facilitator
            $t->string('status', 10)->default('pending'); // pending, signed, disputed
            $t->string('signed_name', 150)->nullable();
            $t->string('dispute', 300)->nullable();
            $t->timestamp('signed_at')->nullable();
            $t->string('ip', 45)->nullable();
            $t->string('channel', 6)->nullable(); // web, app
            $t->timestamps();
            $t->unique(['register_id', 'user_id', 'role']);
        });

        Schema::table('enrolments', function (Blueprint $t): void {
            $t->timestamp('attendance_alerted_at')->nullable();
        });

        foreach (['attendance_registers', 'register_signatures'] as $table) {
            Rls::protect($table);
        }
    }

    public function down(): void
    {
        Schema::table('enrolments', fn (Blueprint $t) => $t->dropColumn('attendance_alerted_at'));
        Schema::dropIfExists('register_signatures');
        Schema::dropIfExists('attendance_registers');
    }
};
