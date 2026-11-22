<?php

declare(strict_types=1);

use Modules\Billing\Models\Package;
use Modules\Settings\Models\Integration;
use Modules\Settings\Models\PlatformSetting;
use Modules\Tenancy\Models\Tenant;
use PragmaRX\Google2FA\Google2FA;

/** Supplier details so tax invoices can be issued. */
function companyReady(): void
{
    asPlatform(fn () => PlatformSetting::query()->updateOrCreate(['key' => 'company'], ['value' => [
        'registered_name' => 'TYM MCS Skills JV (Pty) Ltd', 'vat_number' => '4123456789', 'address_line1' => '1 Main Rd',
        'city' => 'Johannesburg', 'postal_code' => '2001', 'country' => 'ZA', 'email' => 'accounts@skills.test',
        'invoice_prefix' => 'SKL', 'currency' => 'ZAR', 'vat_rate' => 15,
    ]]));
    cache()->forget('settings:company');
}

function enableGateway(string $driver, array $values, string $mode = 'sandbox'): Integration
{
    return asPlatform(fn () => Integration::query()->updateOrCreate(['driver' => $driver], [
        'type' => 'payment', 'credentials' => array_filter($values, fn ($k) => str_contains($k, 'key') || str_contains($k, 'secret') || $k === 'passphrase', ARRAY_FILTER_USE_KEY),
        'settings' => array_filter($values, fn ($k) => ! (str_contains($k, 'key') || str_contains($k, 'secret') || $k === 'passphrase'), ARRAY_FILTER_USE_KEY),
        'mode' => $mode, 'enabled' => true, 'priority' => 10,
    ]));
}

/** Sign in a provider owner with MFA on its own host. */
function signInOwner(object $test, Tenant $tenant, string $email = 'owner@tym.test'): void
{
    makeMember($tenant, ['tp-owner'], ['email' => $email, 'password' => 'correct horse battery staple']);
    $host = "{$tenant->code}.skills.test";
    $test->flushHeaders()->flushSession()->withHeaders(webOrigin($host));
    $test->postJson("https://{$host}/v1/auth/login", ['email' => $email, 'password' => 'correct horse battery staple']);
    $secret = $test->postJson("https://{$host}/v1/auth/mfa/totp/enable")->json('secret');
    $test->postJson("https://{$host}/v1/auth/mfa/totp/confirm", ['code' => app(Google2FA::class)->getCurrentOtp($secret)]);
}

function package(string $code): Package
{
    return Package::query()->where('code', $code)->firstOrFail();
}

/** @return array<string, string> */
function billingProfile(): array
{
    return ['legal_name' => 'Training Young Minds (Pty) Ltd', 'vat_number' => '4987654321', 'address_line1' => '5 Long St', 'city' => 'Pretoria', 'postal_code' => '0002', 'email' => 'finance@tym.test'];
}
