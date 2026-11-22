<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\Tenancy\Domains\CustomHostnames\CloudflareCustomHostnameProvider;
use Modules\Tenancy\Domains\CustomHostnames\CustomHostnameProvider;
use Modules\Tenancy\Enums\SslStatus;

beforeEach(function (): void {
    config([
        'platform.domains.provider' => 'cloudflare',
        'platform.domains.cloudflare.zone_id' => 'zone123',
        'platform.domains.cloudflare.token' => 'secret-token',
    ]);
    $this->app->forgetInstance(CustomHostnameProvider::class);
});

it('is selected by configuration', function (): void {
    expect(app(CustomHostnameProvider::class))->toBeInstanceOf(CloudflareCustomHostnameProvider::class);
});

it('fails fast when credentials are missing', function (): void {
    config(['platform.domains.cloudflare.token' => '']);
    $this->app->forgetInstance(CustomHostnameProvider::class);

    app(CustomHostnameProvider::class);
})->throws(RuntimeException::class, 'CLOUDFLARE_API_TOKEN');

it('registers a hostname with a DV certificate', function (): void {
    Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true, 'result' => ['id' => 'ch_1']])]);

    expect(app(CustomHostnameProvider::class)->register('learn.example.co.za'))->toBe('ch_1');

    Http::assertSent(fn (Request $r) => $r->method() === 'POST'
        && $r->url() === 'https://api.cloudflare.com/client/v4/zones/zone123/custom_hostnames'
        && $r->hasHeader('Authorization', 'Bearer secret-token')
        && $r['hostname'] === 'learn.example.co.za'
        && $r['ssl']['type'] === 'dv');
});

it('maps certificate states', function (string $state, SslStatus $expected): void {
    Http::fake(['*' => Http::response(['success' => true, 'result' => ['id' => 'ch_1', 'ssl' => ['status' => $state]]])]);

    expect(app(CustomHostnameProvider::class)->status('learn.example.co.za', 'ch_1')->ssl)->toBe($expected);
})->with([
    ['active', SslStatus::Active],
    ['pending_validation', SslStatus::Issuing],
    ['initializing', SslStatus::Issuing],
    ['validation_timed_out', SslStatus::Failed],
    ['something_new', SslStatus::Pending],
]);

it('surfaces Cloudflare errors', function (): void {
    Http::fake(['*' => Http::response(['success' => false, 'errors' => [['message' => 'Duplicate custom hostname found.']]], 409)]);

    app(CustomHostnameProvider::class)->register('learn.example.co.za');
})->throws(RuntimeException::class, 'Duplicate custom hostname');

it('treats removing an unknown hostname as done', function (): void {
    Http::fake(['*' => Http::response(['success' => false], 404)]);

    app(CustomHostnameProvider::class)->remove('ch_missing');

    Http::assertSentCount(1);
});
