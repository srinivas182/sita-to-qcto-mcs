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
        // Where a learner's stipend goes. The account number is encrypted; only the last 4 are shown.
        Schema::create('learner_bank_accounts', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $t->string('holder_name', 150);
            $t->string('bank', 40);
            $t->string('branch_code', 6);
            $t->text('account_number'); // encrypted
            $t->string('last4', 4);
            $t->string('account_type', 12); // cheque | savings | transmission
            $t->foreignUlid('proof_file_id')->nullable()->constrained('files')->nullOnDelete();
            $t->foreignUlid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['tenant_id', 'user_id']);
        });

        // The stipend rule lives on the agreement: amount and attendance thresholds.
        Schema::table('grant_agreements', function (Blueprint $t): void {
            $t->unsignedBigInteger('stipend_cents')->nullable();
            $t->unsignedTinyInteger('stipend_full_pct')->default(80);
            $t->unsignedTinyInteger('stipend_hold_pct')->default(50);
            $t->boolean('stipend_prorata')->default(true);
        });

        // One month's stipends for one agreement: proposed from the signed register, confirmed, paid.
        Schema::create('stipend_runs', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('agreement_id')->constrained('grant_agreements')->cascadeOnDelete();
            $t->date('month');
            $t->foreignUlid('register_id')->nullable()->constrained('attendance_registers')->nullOnDelete();
            $t->string('status', 12)->default('draft'); // draft | confirmed | paid
            $t->unsignedBigInteger('total_cents')->default(0);
            $t->foreignUlid('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('confirmed_at')->nullable();
            $t->date('paid_on')->nullable();
            $t->foreignUlid('proof_file_id')->nullable()->constrained('files')->nullOnDelete();
            $t->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['agreement_id', 'month']);
        });

        Schema::create('stipend_lines', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('run_id')->constrained('stipend_runs')->cascadeOnDelete();
            $t->foreignUlid('enrolment_id')->constrained()->restrictOnDelete();
            $t->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $t->unsignedTinyInteger('attendance_pct')->nullable();
            $t->unsignedBigInteger('proposed_cents');
            $t->unsignedBigInteger('amount_cents');
            $t->string('status', 8); // pay | hold
            $t->string('reason', 300)->nullable();
            $t->string('override_reason', 300)->nullable();
            $t->timestamps();
            $t->unique(['run_id', 'enrolment_id']);
            $t->index(['tenant_id', 'user_id']);
        });

        foreach (['learner_bank_accounts', 'stipend_runs', 'stipend_lines'] as $table) {
            Rls::protect($table);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stipend_lines');
        Schema::dropIfExists('stipend_runs');
        Schema::table('grant_agreements', fn (Blueprint $t) => $t->dropColumn(['stipend_cents', 'stipend_full_pct', 'stipend_hold_pct', 'stipend_prorata']));
        Schema::dropIfExists('learner_bank_accounts');
    }
};
