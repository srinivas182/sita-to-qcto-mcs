<?php

declare(strict_types=1);

require_once __DIR__.'/helpers.php';

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Modules\Billing\Models\CreditNote;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Subscription;

beforeEach(function (): void {
    Notification::fake();
    companyReady();
    $this->tym = makeProvider('tym');
    signInOwner($this, $this->tym);
    $this->postJson('https://tym.skills.test/v1/billing/subscribe', ['package_id' => package('starter')->id, 'interval' => 'month', 'profile' => billingProfile()])->assertCreated();
    asTenant($this->tym, fn () => Subscription::query()->update(['trial_ends_at' => now()->subMinute()]));
    Artisan::call('billing:run');
    $this->invoice = asTenant($this->tym, fn () => Invoice::query()->sole());
    signInStaff($this);
    $this->admin = 'https://admin.skills.test/v1/admin';
});

it('shows the billing overview, subscriptions and invoices across providers', function (): void {
    $this->getJson($this->admin.'/billing/overview')->assertOk()
        ->assertJsonPath('subscriptions.active', 1)->assertJsonPath('mrr_cents', 149900)->assertJsonPath('overdue.count', 0);
    $this->getJson($this->admin.'/billing/subscriptions')->assertOk()->assertJsonPath('data.0.tenant.code', 'tym');
    $this->getJson($this->admin.'/billing/invoices?search=SKL-0000')->assertOk()->assertJsonPath('data.0.number', 'SKL-000001');
    expect(substr((string) $this->get($this->admin."/billing/invoices/{$this->invoice->id}/pdf")->assertOk()->getContent(), 0, 4))->toBe('%PDF');
});

it('issues credit notes with their own number and never edits the invoice', function (): void {
    $this->postJson($this->admin."/billing/invoices/{$this->invoice->id}/credit", ['amount_cents' => 10000, 'reason' => 'Goodwill for onboarding delay'])
        ->assertCreated()->assertJsonPath('data.number', 'CN-000001')->assertJsonPath('data.total_cents', 11500);

    $after = asTenant($this->tym, fn () => Invoice::query()->sole());
    expect($after->total_cents)->toBe($this->invoice->total_cents)
        ->and($after->amount_credited_cents)->toBe(11500)
        ->and($after->amountDue())->toBe($this->invoice->total_cents - 11500);

    $this->postJson($this->admin."/billing/invoices/{$this->invoice->id}/credit", ['amount_cents' => 99999999, 'reason' => 'Too much'])
        ->assertStatus(422)->assertJsonPath('error.code', 'invalid_credit');
    expect(asTenant($this->tym, fn () => CreditNote::query()->count()))->toBe(1);
});

it('edits packages and billing rules', function (): void {
    $id = $this->postJson($this->admin.'/billing/packages', ['code' => 'ngo', 'name' => 'NGO', 'base_fee_cents' => 50000, 'public' => false])
        ->assertCreated()->assertJsonPath('data.is_example', false)->json('data.id');
    $this->patchJson($this->admin."/billing/packages/{$id}", ['base_fee_cents' => 45000])->assertOk()->assertJsonPath('data.base_fee_cents', 45000);
    $this->getJson('https://tym.skills.test/v1/packages')->assertJsonMissing(['code' => 'ngo']);

    $this->putJson($this->admin.'/settings/billing-rules', ['trial_days' => 30, 'invoice_due_days' => 10, 'retry_days' => [5, 2], 'read_only_after_days' => 10, 'suspend_after_days' => 5, 'trial_reminder_days' => 3])
        ->assertStatus(422)->assertJsonStructure(['errors' => ['suspend_after_days']]);
    $this->putJson($this->admin.'/settings/billing-rules', ['trial_days' => 30, 'invoice_due_days' => 10, 'retry_days' => [5, 2], 'read_only_after_days' => 10, 'suspend_after_days' => 30, 'trial_reminder_days' => 3])
        ->assertOk()->assertJsonPath('data.retry_days', [2, 5]);
});

it('is for staff with the billing permission only', function (): void {
    signInStaff($this, 'jv-compliance', 'desk@tym-mcs.co.za');
    $this->getJson($this->admin.'/billing/overview')->assertForbidden();
});
