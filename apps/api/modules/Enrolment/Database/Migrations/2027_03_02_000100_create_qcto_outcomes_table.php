<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Support\Database\Rls;

/** What the QCTO said about each learner in a submission: accepted, or rejected with its reason. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qcto_outcomes', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('submission_id')->constrained('qcto_submissions')->cascadeOnDelete();
            $t->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $t->string('status', 10); // accepted, rejected
            $t->text('reason')->nullable();
            $t->foreignUlid('loaded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('resolved_at')->nullable(); // the provider fixed what the QCTO rejected
            $t->foreignUlid('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
            $t->unique(['tenant_id', 'submission_id', 'user_id']);
        });
        Rls::protect('qcto_outcomes');
        Schema::table('qcto_submissions', function (Blueprint $t): void {
            $t->timestampTz('response_loaded_at')->nullable();
            $t->jsonb('response_unmatched')->nullable(); // rows in the QCTO's reply we could not match
        });
    }

    public function down(): void
    {
        Schema::table('qcto_submissions', fn (Blueprint $t) => $t->dropColumn(['response_loaded_at', 'response_unmatched']));
        Schema::dropIfExists('qcto_outcomes');
    }
};
