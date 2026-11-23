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
        // A claim for one tranche: the learners who met it at the time, frozen, with its own invoice number.
        Schema::create('grant_claims', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('agreement_id')->constrained('grant_agreements')->restrictOnDelete();
            $t->foreignUlid('tranche_id')->constrained('grant_tranches')->restrictOnDelete();
            $t->string('number', 40); // e.g. TYM-CLM-2027-00001, no gaps
            $t->string('status', 12)->default('draft'); // draft | sent | queried | paid | rejected | void
            $t->json('learners'); // [{enrolment_id, user_id, name, amount_cents, disability_cents}]
            $t->unsignedBigInteger('amount_cents');
            $t->date('sent_on')->nullable();
            $t->date('paid_on')->nullable();
            $t->unsignedBigInteger('paid_cents')->nullable();
            $t->foreignUlid('proof_file_id')->nullable()->constrained('files')->nullOnDelete();
            $t->string('closing_note', 500)->nullable();
            $t->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['tenant_id', 'number']);
            $t->index(['tenant_id', 'tranche_id']);
        });

        // A funder's query or rejection reason, per learner or document, until fixed.
        Schema::create('claim_queries', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('claim_id')->constrained('grant_claims')->cascadeOnDelete();
            $t->foreignUlid('enrolment_id')->nullable()->constrained()->nullOnDelete();
            $t->string('problem', 500);
            $t->date('raised_on');
            $t->string('resolution', 500)->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->foreignUlid('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Rls::protect('grant_claims');
        Rls::protect('claim_queries');
    }

    public function down(): void
    {
        Schema::dropIfExists('claim_queries');
        Schema::dropIfExists('grant_claims');
    }
};
