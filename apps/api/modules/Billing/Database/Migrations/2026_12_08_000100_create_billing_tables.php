<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Support\Database\Rls;

/**
 * Billing. Amounts are integers in cents (ZAR). Prices exclude VAT; VAT is added on
 * the invoice at the rate in Settings → Company.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Packages: the public price list, managed by the Super Admin.
        Schema::create('packages', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('code', 40)->unique();
            $table->string('name', 100);
            $table->string('description', 500)->nullable();
            $table->jsonb('features')->nullable();
            $table->unsignedInteger('base_fee_cents');
            $table->unsignedInteger('per_learner_cents')->default(0);
            $table->unsignedInteger('included_learners')->default(0);
            $table->unsignedInteger('max_learners')->nullable();
            $table->unsignedInteger('max_centres')->nullable();
            $table->unsignedSmallInteger('annual_discount_percent')->default(0);
            $table->unsignedSmallInteger('trial_days')->default(14);
            $table->boolean('is_example')->default(false);
            $table->boolean('active')->default(true);
            $table->boolean('public')->default(true);
            $table->unsignedSmallInteger('sort')->default(100);
            $table->timestampsTz();
        });

        // The provider's details printed as the recipient on tax invoices.
        Schema::create('billing_profiles', function (Blueprint $table): void {
            $table->ulid('tenant_id')->primary();
            $table->string('legal_name', 200);
            $table->string('vat_number', 20)->nullable();
            $table->string('registration_number', 40)->nullable();
            $table->string('address_line1', 200)->nullable();
            $table->string('address_line2', 200)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('province', 100)->nullable();
            $table->string('postal_code', 10)->nullable();
            $table->string('email', 254);
            $table->string('purchase_order', 60)->nullable();
            $table->timestampsTz();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
        Rls::protect('billing_profiles');

        Schema::create('payment_methods', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->string('driver', 40);
            $table->text('token');                       // encrypted
            $table->string('customer_ref', 254)->nullable();
            $table->string('brand', 30)->nullable();
            $table->string('last4', 4)->nullable();
            $table->unsignedTinyInteger('exp_month')->nullable();
            $table->unsignedSmallInteger('exp_year')->nullable();
            $table->string('fingerprint', 100)->nullable();
            $table->timestampsTz();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'driver']);
        });
        Rls::protect('payment_methods');

        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->unique();
            $table->foreignUlid('package_id')->constrained('packages');
            $table->foreignUlid('pending_package_id')->nullable()->constrained('packages');
            $table->string('interval', 10)->default('month');      // month | year
            $table->string('status', 16);                           // trialing, active, past_due, read_only, suspended, cancelled
            $table->string('collection', 10)->default('invoice');   // auto (card) | invoice (pay each invoice)
            $table->foreignUlid('payment_method_id')->nullable()->constrained('payment_methods')->nullOnDelete();
            $table->timestampTz('trial_ends_at')->nullable();
            $table->timestampTz('current_period_start')->nullable();
            $table->timestampTz('current_period_end')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampTz('past_due_since')->nullable();
            $table->timestampTz('trial_reminder_sent_at')->nullable();
            $table->timestampsTz();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['status', 'current_period_end']);
        });
        Rls::protect('subscriptions');

        // Sequential numbers per series (INV, CN). Platform only.
        Schema::create('document_sequences', function (Blueprint $table): void {
            $table->string('series', 20)->primary();
            $table->unsignedBigInteger('next_number')->default(1);
            $table->timestampsTz();
        });
        Rls::protectWith('document_sequences', "current_setting('app.context', true) = 'platform'");

        Schema::create('invoices', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->foreignUlid('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number', 40)->nullable()->unique();
            $table->string('status', 16);                 // open, paid, void
            $table->string('currency', 3)->default('ZAR');
            $table->unsignedBigInteger('subtotal_cents');
            $table->decimal('vat_rate', 5, 2);
            $table->unsignedBigInteger('vat_cents');
            $table->unsignedBigInteger('total_cents');
            $table->unsignedBigInteger('amount_paid_cents')->default(0);
            $table->unsignedBigInteger('amount_credited_cents')->default(0);
            $table->jsonb('supplier');
            $table->jsonb('recipient');
            $table->string('eft_reference', 30)->unique();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->timestampTz('issued_at');
            $table->timestampTz('due_at');
            $table->timestampTz('paid_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('next_attempt_at')->nullable();
            $table->ulid('pdf_file_id')->nullable();
            $table->timestampsTz();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'issued_at']);
            $table->index(['status', 'due_at']);
        });
        Rls::protect('invoices');

        Schema::create('invoice_lines', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->foreignUlid('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);                   // base, learners, proration, adjustment
            $table->string('description', 300);
            $table->unsignedInteger('quantity');
            $table->bigInteger('unit_cents');
            $table->bigInteger('amount_cents');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
        Rls::protect('invoice_lines');

        Schema::create('credit_notes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->foreignUlid('invoice_id')->constrained();
            $table->string('number', 40)->unique();
            $table->string('reason', 500);
            $table->unsignedBigInteger('subtotal_cents');
            $table->unsignedBigInteger('vat_cents');
            $table->unsignedBigInteger('total_cents');
            $table->jsonb('supplier');
            $table->jsonb('recipient');
            $table->ulid('issued_by')->nullable();
            $table->timestampTz('issued_at');
            $table->ulid('pdf_file_id')->nullable();
            $table->timestampsTz();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
        Rls::protect('credit_notes');

        Schema::create('payments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->foreignUlid('invoice_id')->constrained();
            $table->string('reference', 40)->unique();    // ours, sent to the gateway
            $table->string('driver', 40);                 // payment.paystack, manual.eft, …
            $table->string('gateway_reference', 120)->nullable();
            $table->string('method', 20);                 // card, saved_card, instant_eft, eft
            $table->unsignedBigInteger('amount_cents');
            $table->string('status', 16);                 // pending, succeeded, failed, cancelled
            $table->boolean('save_method')->default(false);
            $table->string('failure_message', 300)->nullable();
            $table->ulid('recorded_by')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampsTz();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'created_at']);
        });
        Rls::protect('payments');

        // Every webhook received, once. Platform only (the tenant is found from the payment).
        Schema::create('gateway_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('driver', 40);
            $table->string('event_id', 120);
            $table->string('type', 60);
            $table->string('reference', 120)->nullable();
            $table->text('payload');                      // encrypted
            $table->string('result', 300)->nullable();
            $table->timestampTz('processed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['driver', 'event_id']);
        });
        Rls::protectWith('gateway_events', "current_setting('app.context', true) = 'platform'");

        $this->seedExamplePackages();
    }

    /** Example price list so the platform can be demonstrated. Clearly marked; change or delete freely. */
    private function seedExamplePackages(): void
    {
        $now = now();
        $rows = [
            ['starter', 'Starter', 'For a single small centre starting with occupational programmes.', 149900, 2500, 25, 100, 1, 10,
                ['1 centre', 'Up to 100 active learners', '25 learners included', 'QCTO data exports', 'Email support']],
            ['growth', 'Growth', 'For established providers running several programmes and centres.', 399900, 1900, 100, 1000, 5, 20,
                ['Up to 5 centres', 'Up to 1 000 active learners', '100 learners included', 'Custom domain', 'Priority support']],
            ['enterprise', 'Enterprise', 'For large provider groups with many centres.', 999900, 1200, 400, null, null, 30,
                ['Unlimited centres', 'Unlimited learners', '400 learners included', 'Dedicated success manager', 'SLA']],
        ];

        foreach ($rows as [$code, $name, $description, $base, $perLearner, $included, $maxLearners, $maxCentres, $sort, $features]) {
            DB::table('packages')->insert([
                'id' => (string) Str::ulid(),
                'code' => $code,
                'name' => $name,
                'description' => $description,
                'features' => json_encode($features),
                'base_fee_cents' => $base,
                'per_learner_cents' => $perLearner,
                'included_learners' => $included,
                'max_learners' => $maxLearners,
                'max_centres' => $maxCentres,
                'annual_discount_percent' => 15,
                'trial_days' => 14,
                'is_example' => true,
                'active' => true,
                'public' => true,
                'sort' => $sort,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        foreach (['gateway_events', 'payments', 'credit_notes', 'invoice_lines', 'invoices', 'document_sequences', 'subscriptions', 'payment_methods', 'billing_profiles', 'packages'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
