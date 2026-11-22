<?php

declare(strict_types=1);

require_once __DIR__.'/helpers.php';

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Subscription;
use Modules\Billing\Notifications\AccountRestricted;
use Modules\Billing\Notifications\InvoiceIssued;
use Modules\Billing\Notifications\TrialEnding;
use Modules\Settings\Models\PlatformSetting;

beforeEach(function (): void {
    Notification::fake();
    companyReady();
    $this->tym = makeProvider('tym', ['legal_name' => 'Training Young Minds (Pty) Ltd']);
    signInOwner($this, $this->tym);
    $this->base = 'https://tym.skills.test/v1';
});

it('lists example packages publicly, clearly marked', function (): void {
    $this->getJson($this->base.'/packages')->assertOk()
        ->assertJsonPath('example_pricing', true)
        ->assertJsonPath('vat_inclusive', false)
        ->assertJsonPath('data.0.code', 'starter')
        ->assertJsonPath('data.0.is_example', true)
        ->assertJsonPath('data.0.annual_fee_cents', (int) round(149900 * 12 * 0.85));
});

it('starts a trial, invoices when it ends, and counts active learners', function (): void {
    $this->postJson($this->base.'/billing/subscribe', ['package_id' => package('starter')->id, 'interval' => 'month', 'profile' => billingProfile()])
        ->assertCreated()->assertJsonPath('subscription.status', 'trialing')->assertJsonPath('subscription.package.code', 'starter');

    foreach (range(1, 30) as $i) {
        makeMember($this->tym, ['learner']);
    }

    $billing = $this->getJson($this->base.'/billing')->assertOk()->json();
    expect($billing['estimate']['active_learners'])->toBe(30)
        ->and($billing['estimate']['lines'][1]['quantity'])->toBe(5)
        ->and($billing['estimate']['subtotal_cents'])->toBe(149900 + 5 * 2500);
    $this->getJson($this->base.'/me')->assertJsonPath('tenant.billing.status', 'trialing');

    // Reminder 3 days before the end, then the first invoice when it ends.
    asTenant($this->tym, fn () => Subscription::query()->update(['trial_ends_at' => now()->addDays(2)]));
    Artisan::call('billing:run');
    Notification::assertSentOnDemand(TrialEnding::class);

    asTenant($this->tym, fn () => Subscription::query()->update(['trial_ends_at' => now()->subMinute()]));
    Artisan::call('billing:run');
    Notification::assertSentOnDemand(InvoiceIssued::class);

    $invoice = asTenant($this->tym, fn () => Invoice::query()->with('lines')->sole());
    expect($invoice->number)->toBe('SKL-000001')
        ->and($invoice->subtotal_cents)->toBe(162400)
        ->and($invoice->vat_cents)->toBe(24360)
        ->and($invoice->total_cents)->toBe(186760)
        ->and($invoice->supplier['vat_number'])->toBe('4123456789')
        ->and($invoice->recipient['vat_number'])->toBe('4987654321')
        ->and($invoice->eft_reference)->toStartWith('TYM-')
        ->and($invoice->lines)->toHaveCount(2);

    $this->getJson($this->base.'/billing')->assertJsonPath('subscription.status', 'active')->assertJsonCount(1, 'open_invoices');

    $pdf = $this->get($this->base."/invoices/{$invoice->id}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(substr((string) $pdf->getContent(), 0, 4))->toBe('%PDF');
});

it('moves from past due to read-only to suspended, and back once paid', function (): void {
    $this->postJson($this->base.'/billing/subscribe', ['package_id' => package('starter')->id, 'interval' => 'month', 'profile' => billingProfile()])->assertCreated();
    asTenant($this->tym, fn () => Subscription::query()->update(['trial_ends_at' => now()->subMinute()]));
    Artisan::call('billing:run');

    $overdue = function (int $days): void {
        asTenant($this->tym, fn () => Invoice::query()->update(['due_at' => now()->subDays($days)]));
        Artisan::call('billing:run');
    };

    $overdue(2);
    $this->getJson($this->base.'/me')->assertJsonPath('tenant.billing.status', 'past_due');
    $this->patchJson($this->base.'/tenant/branding', ['branding' => ['tagline' => 'Still fine']])->assertOk();

    $overdue(8);
    $this->getJson($this->base.'/me')->assertJsonPath('tenant.billing.status', 'read_only');
    Notification::assertSentOnDemand(AccountRestricted::class);
    $this->patchJson($this->base.'/tenant/branding', ['branding' => ['tagline' => 'Blocked']])
        ->assertStatus(402)->assertJsonPath('error.code', 'subscription_read_only');
    $this->getJson($this->base.'/users')->assertOk();
    $this->getJson($this->base.'/billing')->assertOk();

    $overdue(22);
    $this->getJson($this->base.'/users')->assertStatus(402)->assertJsonPath('error.code', 'subscription_suspended');
    $this->getJson($this->base.'/billing')->assertOk();
    $this->getJson($this->base.'/invoices')->assertOk()->assertJsonPath('data.0.status', 'overdue');

    // Super Admin records the EFT: everything opens again.
    $invoice = asTenant($this->tym, fn () => Invoice::query()->sole());
    signInStaff($this);
    $this->postJson("https://admin.skills.test/v1/admin/billing/invoices/{$invoice->id}/eft", ['amount_cents' => $invoice->total_cents, 'bank_reference' => 'FNB 0042'])
        ->assertCreated()->assertJsonPath('data.status', 'succeeded');

    expect(asTenant($this->tym, fn () => Subscription::query()->sole()->status->value))->toBe('active')
        ->and(asTenant($this->tym, fn () => Invoice::query()->sole()->status))->toBe('paid');
});

it('upgrades with a prorated invoice, downgrades and cancels at period end', function (): void {
    $this->postJson($this->base.'/billing/subscribe', ['package_id' => package('starter')->id, 'interval' => 'month', 'profile' => billingProfile()])->assertCreated();
    asTenant($this->tym, fn () => Subscription::query()->update(['trial_ends_at' => now()->subMinute()]));
    Artisan::call('billing:run');

    $this->postJson($this->base.'/billing/change', ['package_id' => package('growth')->id, 'interval' => 'month'])
        ->assertOk()->assertJsonPath('subscription.package.code', 'growth');
    $proration = asTenant($this->tym, fn () => Invoice::query()->orderByDesc('number')->with('lines')->first());
    expect($proration->number)->toBe('SKL-000002')
        ->and($proration->lines->first()->kind)->toBe('proration')
        ->and($proration->subtotal_cents)->toBeGreaterThan(240000)->toBeLessThanOrEqual(250000);

    $this->postJson($this->base.'/billing/change', ['package_id' => package('starter')->id, 'interval' => 'month'])
        ->assertOk()->assertJsonPath('subscription.package.code', 'growth')->assertJsonPath('subscription.pending_package.code', 'starter');

    // Pay what is open so the renewal runs cleanly, then end the period.
    asTenant($this->tym, fn () => Invoice::query()->update(['status' => 'paid', 'paid_at' => now()]));
    asTenant($this->tym, fn () => Subscription::query()->update(['current_period_end' => now()->subMinute()]));
    Artisan::call('billing:run');
    $this->getJson($this->base.'/billing')->assertJsonPath('subscription.package.code', 'starter')->assertJsonPath('subscription.pending_package', null);

    $this->postJson($this->base.'/billing/cancel')->assertOk()->assertJsonPath('subscription.cancel_at_period_end', true);
    asTenant($this->tym, fn () => Invoice::query()->update(['status' => 'paid', 'paid_at' => now()]));
    asTenant($this->tym, fn () => Subscription::query()->update(['current_period_end' => now()->subMinute()]));
    Artisan::call('billing:run');
    $this->getJson($this->base.'/billing')->assertJsonPath('subscription.status', 'cancelled')->assertJsonPath('estimate', null);
    // Ended plans keep their records readable, but nothing can be changed.
    $this->patchJson($this->base.'/tenant/branding', ['branding' => ['tagline' => 'x']])->assertStatus(402);
});

it('needs company details before invoicing and keeps billing to owners', function (): void {
    asPlatform(fn () => PlatformSetting::query()->where('key', 'company')->delete());
    cache()->forget('settings:company');
    $this->postJson($this->base.'/billing/subscribe', ['package_id' => package('starter')->id, 'interval' => 'month', 'profile' => billingProfile()])->assertCreated();
    asTenant($this->tym, fn () => Subscription::query()->update(['trial_ends_at' => now()->subMinute()]));
    expect(Artisan::call('billing:run'))->toBe(1);

    $this->postJson($this->base.'/billing/subscribe', ['package_id' => package('starter')->id, 'interval' => 'month', 'profile' => billingProfile()])
        ->assertStatus(409)->assertJsonPath('error.code', 'already_subscribed');
    $this->postJson($this->base.'/billing/subscribe', ['package_id' => package('starter')->id, 'interval' => 'week'])->assertStatus(422);

    makeMember($this->tym, ['facilitator'], ['email' => 'f@tym.test', 'password' => 'correct horse battery staple']);
    $this->flushSession()->withHeaders(webOrigin('tym.skills.test'));
    $this->postJson($this->base.'/auth/logout');
    $this->postJson($this->base.'/auth/login', ['email' => 'f@tym.test', 'password' => 'correct horse battery staple']);
    $this->getJson($this->base.'/billing')->assertForbidden();
});

it('keeps invoices inside their provider', function (): void {
    $this->postJson($this->base.'/billing/subscribe', ['package_id' => package('starter')->id, 'interval' => 'month', 'profile' => billingProfile()])->assertCreated();
    asTenant($this->tym, fn () => Subscription::query()->update(['trial_ends_at' => now()->subMinute()]));
    Artisan::call('billing:run');
    $invoice = asTenant($this->tym, fn () => Invoice::query()->sole());

    $other = makeProvider('other');
    expect(asTenant($other, fn () => Invoice::query()->count()))->toBe(0);
    signInOwner($this, $other, 'o@other.test');
    $this->getJson("https://other.skills.test/v1/invoices/{$invoice->id}")->assertNotFound();
});
