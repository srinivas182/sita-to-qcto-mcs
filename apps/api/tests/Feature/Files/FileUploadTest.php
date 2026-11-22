<?php

declare(strict_types=1);

use Illuminate\Testing\TestResponse;
use Modules\Files\Models\File;
use Modules\Files\Scanning\Scanner;
use Modules\Files\Scanning\ScannerUnavailable;
use Modules\Files\Scanning\ScanResult;
use Modules\Files\Support\FileStatus;
use Modules\Settings\Exceptions\SettingsError;
use Modules\Settings\Models\Integration;
use PragmaRX\Google2FA\Google2FA;

/** A small real PNG. */
function pngBytes(int $w = 640, int $h = 320): string
{
    $img = imagecreatetruecolor($w, $h);
    imagefill($img, 0, 0, (int) imagecolorallocate($img, 67, 56, 202));
    ob_start();
    imagepng($img);

    return (string) ob_get_clean();
}

beforeEach(function (): void {
    config(['files.default.root' => 'files-test-'.uniqid()]);
    $this->tym = makeProvider('tym', ['trading_name' => 'Training Young Minds']);
    $this->owner = makeMember($this->tym, ['tp-owner'], ['email' => 'sekal@tym.co.za', 'password' => 'correct horse battery staple']);

    $this->withHeaders(webOrigin('tym.skills.test'));
    $this->postJson('https://tym.skills.test/v1/auth/login', ['email' => 'sekal@tym.co.za', 'password' => 'correct horse battery staple']);
    $secret = $this->postJson('https://tym.skills.test/v1/auth/mfa/totp/enable')->json('secret');
    $this->postJson('https://tym.skills.test/v1/auth/mfa/totp/confirm', ['code' => app(Google2FA::class)->getCurrentOtp($secret)]);
    $this->base = 'https://tym.skills.test/v1';
});

/** @return array{0: string, 1: array<string, mixed>} file id and the upload target */
function startUpload(object $test, string $bytes, string $mime = 'image/png', string $name = 'logo.png'): array
{
    $res = $test->postJson($test->base.'/files', ['purpose' => 'tenant_logo', 'name' => $name, 'mime' => $mime, 'size' => strlen($bytes)])
        ->assertCreated()->assertJsonPath('data.status', 'pending');

    return [$res->json('data.id'), $res->json('upload')];
}

function sendBytes(object $test, string $id, string $bytes, string $mime = 'image/png'): TestResponse
{
    return $test->call('PUT', $test->base."/files/{$id}/content", [], [], [], [
        'CONTENT_TYPE' => $mime, 'HTTP_ORIGIN' => 'https://tym.skills.test', 'HTTP_REFERER' => 'https://tym.skills.test/',
    ], $bytes);
}

it('uploads a logo, scans it and makes variants', function (): void {
    $bytes = pngBytes();
    [$id, $upload] = startUpload($this, $bytes, name: '../../evil/Logo ü.png');

    expect($upload['via'])->toBe('api')->and($upload['url'])->toEndWith("/v1/files/{$id}/content");

    sendBytes($this, $id, $bytes)->assertOk();
    $this->postJson($this->base."/files/{$id}/complete")->assertOk()
        ->assertJsonPath('data.status', 'ready')
        ->assertJsonPath('data.name', 'Logo ü.png')
        ->assertJsonPath('data.variants.lg.width', 512)
        ->assertJsonPath('data.variants.sm.width', 128);

    $file = asTenant($this->tym, fn () => File::query()->findOrFail($id));
    expect($file->sha256)->toBe(hash('sha256', $bytes))
        ->and($file->path)->toStartWith($this->tym->id.'/tenant_logo/')->not->toContain('evil');

    // Public variant for the sign-in page; cached for a year.
    $image = $this->flushHeaders()->get($this->base."/images/{$id}/lg")->assertOk()
        ->assertHeader('Content-Type', 'image/webp')->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($image->headers->get('Cache-Control'))->toContain('immutable')
        ->and(substr((string) $image->getContent(), 8, 4))->toBe('WEBP');
    $this->get($this->base."/images/{$id}/xx")->assertNotFound();

    // Use it as the brand logo.
    $this->withHeaders(webOrigin('tym.skills.test'))
        ->patchJson($this->base.'/tenant/branding', ['branding' => ['logo_file_id' => $id]])
        ->assertOk()->assertJsonPath('data.branding.logo_url', "/v1/images/{$id}/lg");
    $this->getJson($this->base.'/branding')->assertJsonPath('branding.logo_url', "/v1/images/{$id}/lg");

    // Download the original (local store: through the API).
    $this->getJson($this->base."/files/{$id}/download")->assertOk()->assertJsonPath('via', 'api');
    $download = $this->get($this->base."/files/{$id}/content")->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    expect($download->streamedContent())->toBe($bytes);
});

it('refuses wrong types, oversized files and short or long uploads', function (): void {
    $this->postJson($this->base.'/files', ['purpose' => 'tenant_logo', 'name' => 'a.svg', 'mime' => 'image/svg+xml', 'size' => 10])
        ->assertStatus(422)->assertJsonPath('error.message', 'This type of file is not allowed here.');
    $this->postJson($this->base.'/files', ['purpose' => 'tenant_logo', 'name' => 'a.png', 'mime' => 'image/png', 'size' => 3 * 1024 * 1024])
        ->assertStatus(422)->assertJsonPath('error.code', 'invalid_file');
    $this->postJson($this->base.'/files', ['purpose' => 'application_document', 'name' => 'a.pdf', 'mime' => 'application/pdf', 'size' => 10])
        ->assertStatus(422)->assertJsonPath('error.code', 'unknown_purpose');

    [$id] = startUpload($this, 'x'.str_repeat('y', 99));
    sendBytes($this, $id, str_repeat('z', 101))->assertStatus(422)->assertJsonPath('error.code', 'size_mismatch');
    sendBytes($this, $id, 'short')->assertStatus(422);
    $this->postJson($this->base."/files/{$id}/complete")->assertStatus(422)->assertJsonPath('error.code', 'not_uploaded');
});

it('rejects content that is not what it claims, and never serves it', function (): void {
    $fake = '<html><script>alert(1)</script></html>'.str_repeat(' ', 100);
    [$id] = startUpload($this, $fake);
    sendBytes($this, $id, $fake)->assertOk();

    $this->postJson($this->base."/files/{$id}/complete")->assertOk()->assertJsonPath('data.status', 'rejected');
    $this->get($this->base."/images/{$id}/lg")->assertNotFound();
    $this->getJson($this->base."/files/{$id}/download")->assertStatus(409);
    $this->patchJson($this->base.'/tenant/branding', ['branding' => ['logo_file_id' => $id]])->assertStatus(422);
});

it('rejects a file the virus scanner flags', function (): void {
    $this->app->instance(Scanner::class, new class implements Scanner
    {
        public function scan($stream): ScanResult
        {
            return ScanResult::infected('Eicar-Signature');
        }
    });

    $bytes = pngBytes();
    [$id] = startUpload($this, $bytes);
    sendBytes($this, $id, $bytes)->assertOk();

    $this->postJson($this->base."/files/{$id}/complete")->assertOk()
        ->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.status_reason', 'A virus was found in this file.');
});

it('keeps files inside their provider', function (): void {
    $bytes = pngBytes();
    [$id] = startUpload($this, $bytes);
    sendBytes($this, $id, $bytes)->assertOk();
    $this->postJson($this->base."/files/{$id}/complete")->assertOk();

    $other = makeProvider('other');
    expect(asTenant($other, fn () => File::query()->find($id)))->toBeNull();
    $this->flushHeaders()->get('https://other.skills.test/v1/images/'.$id.'/lg')->assertNotFound();

    // A learner of the same provider may not upload a logo.
    $learner = makeMember($this->tym, ['learner'], ['email' => 'l@tym.test', 'password' => 'correct horse battery staple']);
    $this->flushSession()->withHeaders(webOrigin('tym.skills.test'));
    $this->postJson($this->base.'/auth/logout');
    $this->postJson($this->base.'/auth/login', ['email' => 'l@tym.test', 'password' => 'correct horse battery staple']);
    $this->postJson($this->base.'/files', ['purpose' => 'tenant_logo', 'name' => 'a.png', 'mime' => 'image/png', 'size' => 10])->assertForbidden();
    expect($learner)->not->toBeNull();
});

it('writes to the storage chosen in settings and keeps old files readable', function (): void {
    $first = pngBytes();
    [$old] = startUpload($this, $first);
    sendBytes($this, $old, $first)->assertOk();
    $this->postJson($this->base."/files/{$old}/complete")->assertOk();

    $store = asPlatform(fn () => Integration::query()->create([
        'type' => 'storage', 'driver' => 'storage.local', 'settings' => ['root' => 'files-alt-'.uniqid()], 'enabled' => true,
    ]));

    $second = pngBytes(200, 200);
    [$new] = startUpload($this, $second);
    sendBytes($this, $new, $second)->assertOk();
    $this->postJson($this->base."/files/{$new}/complete")->assertOk();

    [$oldFile, $newFile] = asTenant($this->tym, fn () => [File::query()->findOrFail($old), File::query()->findOrFail($new)]);
    expect($oldFile->integration_id)->toBeNull()->and($newFile->integration_id)->toBe($store->id);
    expect($this->get($this->base."/files/{$old}/content")->streamedContent())->toBe($first);
    expect($this->get($this->base."/files/{$new}/content")->streamedContent())->toBe($second);

    // The store holding files cannot be deleted.
    expect(fn () => asPlatform(fn () => $store->delete()))->toThrow(SettingsError::class);
});

it('fails a file safely when the scanner is down, never marking it ready', function (): void {
    $this->app->instance(Scanner::class, new class implements Scanner
    {
        public function scan($stream): ScanResult
        {
            throw new ScannerUnavailable('down');
        }
    });

    $bytes = pngBytes();
    [$id] = startUpload($this, $bytes);
    sendBytes($this, $id, $bytes)->assertOk();

    try {
        $this->postJson($this->base."/files/{$id}/complete");
    } catch (Throwable) {
        // The sync queue rethrows; a real worker retries with back-off.
    }

    expect(asTenant($this->tym, fn () => File::query()->findOrFail($id)->status))->not->toBe(FileStatus::Ready);
});
