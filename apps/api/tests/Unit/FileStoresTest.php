<?php

declare(strict_types=1);

use Modules\Files\Scanning\ClamdScanner;
use Modules\Files\Scanning\ScannerUnavailable;
use Modules\Files\Storage\AzureStore;
use Modules\Files\Storage\ContentDisposition;
use Modules\Files\Storage\S3Store;
use Tests\TestCase;

uses(TestCase::class);

it('signs direct uploads to S3 for one object, type and size', function (): void {
    $store = new S3Store(['key' => 'AKIAEXAMPLE', 'secret' => 'secret', 'region' => 'af-south-1', 'bucket' => 'skills-files', 'prefix' => 'prod/']);
    $target = $store->uploadTarget('t1/tenant_logo/2026/12/abc', 'image/png', 1234, now()->addMinutes(15));

    expect($target?->method)->toBe('PUT')
        ->and($target?->url)->toStartWith('https://skills-files.s3.af-south-1.amazonaws.com/prod/t1/tenant_logo/2026/12/abc?')
        ->toContain('X-Amz-Signature=')
        ->and($target?->headers['Content-Type'])->toBe('image/png');
    // The SDK counts the expiry from its own clock, so a slow run can lose a few seconds.
    parse_str((string) parse_url((string) $target?->url, PHP_URL_QUERY), $query);
    expect((int) $query['X-Amz-Expires'])->toBeGreaterThan(880)->toBeLessThanOrEqual(900);

    $download = $store->downloadUrl('x/y', 'Report ü.pdf', 'application/pdf', now()->addMinutes(10));
    expect(urldecode((string) $download))->toContain('response-content-disposition=attachment');
});

it('signs direct uploads to Azure with create and write only', function (): void {
    $store = new AzureStore(['account_name' => 'skillsfiles', 'account_key' => base64_encode(str_repeat('k', 64)), 'container' => 'files']);
    $target = $store->uploadTarget('t1/logo/abc', 'image/png', 10, now()->addMinutes(15));

    parse_str((string) parse_url((string) $target?->url, PHP_URL_QUERY), $query);
    expect($target?->url)->toStartWith('https://skillsfiles.blob.core.windows.net/files/t1/logo/abc?')
        ->and($query['sp'])->toBe('cw')
        ->and($query)->toHaveKey('sig')
        ->and($target?->headers['x-ms-blob-type'])->toBe('BlockBlob');
});

it('builds a safe attachment header', function (): void {
    expect(ContentDisposition::attachment('a"b;ü.pdf'))->toBe('attachment; filename="a_b_u.pdf"; filename*=UTF-8\'\'a%22b%3B%C3%BC.pdf');
});

it('speaks the clamd INSTREAM protocol', function (string $reply, ?string $signature): void {
    [$ours, $theirs] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    fwrite($theirs, $reply);
    stream_socket_shutdown($theirs, STREAM_SHUT_WR);

    $file = fopen('php://memory', 'w+b');
    fwrite($file, 'hello');
    rewind($file);

    $result = (new ClamdScanner(fn () => $ours))->scan($file);
    $sent = stream_get_contents($theirs);

    expect($sent)->toBe("zINSTREAM\0".pack('N', 5).'hello'.pack('N', 0))
        ->and($result->clean)->toBe($signature === null)
        ->and($result->signature)->toBe($signature);
})->with([
    'clean' => ["stream: OK\0", null],
    'infected' => ["stream: Eicar-Signature FOUND\0", 'Eicar-Signature'],
]);

it('treats a strange clamd answer as unavailable', function (): void {
    [$ours, $theirs] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    fwrite($theirs, "INSTREAM size limit exceeded. ERROR\0");
    stream_socket_shutdown($theirs, STREAM_SHUT_WR);
    $file = fopen('php://memory', 'w+b');

    (new ClamdScanner(fn () => $ours))->scan($file);
})->throws(ScannerUnavailable::class);
