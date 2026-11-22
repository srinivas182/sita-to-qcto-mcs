<?php

declare(strict_types=1);

use Modules\Audit\Models\AuditLog;
use Modules\Settings\Models\PlatformSetting;

beforeEach(function (): void {
    $this->url = 'https://admin.skills.test/v1/admin/settings/company';
});

it('saves the company details printed on tax invoices', function (): void {
    signInStaff($this);

    $this->getJson($this->url)->assertOk()
        ->assertJsonPath('data.currency', 'ZAR')->assertJsonPath('data.vat_rate', 15)->assertJsonPath('complete', false);

    $this->putJson($this->url, [
        'registered_name' => 'TYM MCS Skills JV (Pty) Ltd',
        'registration_number' => '2026/123456/07',
        'vat_number' => '4123456789',
        'address_line1' => '12 Main Road',
        'city' => 'Johannesburg',
        'province' => 'Gauteng',
        'postal_code' => '2001',
        'country' => 'ZA',
        'email' => 'accounts@skills.test',
        'invoice_prefix' => 'SKL',
        'currency' => 'ZAR',
        'vat_rate' => 15,
    ])->assertOk()->assertJsonPath('data.vat_number', '4123456789')->assertJsonPath('complete', true);

    expect(asPlatform(fn () => PlatformSetting::query()->find('company')?->value['registered_name']))->toBe('TYM MCS Skills JV (Pty) Ltd')
        ->and(asPlatform(fn () => AuditLog::query()->where('action', 'settings.company_updated')->exists()))->toBeTrue();
});

it('checks the VAT number and invoice prefix', function (): void {
    signInStaff($this);

    $this->putJson($this->url, [
        'registered_name' => 'JV', 'address_line1' => 'x', 'city' => 'y', 'country' => 'ZA', 'email' => 'a@b.test',
        'invoice_prefix' => 'inv 1', 'currency' => 'ZAR', 'vat_rate' => 15, 'vat_number' => '1234',
    ])->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid')
        ->assertJsonPath('errors.vat_number.0', 'A South African VAT number has 10 digits and starts with 4.')
        ->assertJsonStructure(['errors' => ['invoice_prefix']]);
});

it('is for platform staff with the settings permission only', function (): void {
    signInStaff($this, 'jv-compliance', 'desk@tym-mcs.co.za');
    $this->getJson($this->url)->assertForbidden();

    $tym = makeProvider('tym');
    makeMember($tym, ['tp-owner'], ['email' => 'o@tym.test', 'password' => 'correct horse battery staple']);
    $this->flushHeaders()->flushSession()->withHeaders(webOrigin('tym.skills.test'));
    $this->postJson('https://tym.skills.test/v1/auth/login', ['email' => 'o@tym.test', 'password' => 'correct horse battery staple']);
    $this->getJson('https://tym.skills.test/v1/admin/settings/company')->assertForbidden();
});
