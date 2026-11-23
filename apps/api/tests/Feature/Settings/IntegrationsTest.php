<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Audit\Models\AuditLog;
use Modules\Settings\Models\Integration;

beforeEach(function (): void {
    $this->base = 'https://admin.skills.test/v1/admin/settings/integrations';
});

it('lists every type and the South African providers', function (): void {
    signInStaff($this);

    $types = collect($this->getJson($this->base)->assertOk()->json('data'))->keyBy('type');
    $drivers = fn (string $type) => collect($types[$type]['drivers'])->pluck('driver')->all();

    expect($types->keys()->all())->toBe(['payment', 'sms', 'whatsapp', 'email', 'storage', 'esign'])
        ->and($drivers('payment'))->toContain('payment.paystack', 'payment.payfast', 'payment.peach', 'payment.ozow', 'payment.yoco', 'payment.stitch', 'payment.ikhokha')
        ->and($drivers('sms'))->toContain('sms.clickatell', 'sms.bulksms', 'sms.smsportal', 'sms.panacea', 'sms.infobip', 'sms.twilio')
        ->and($drivers('whatsapp'))->toBe(['whatsapp.meta'])
        ->and($drivers('storage'))->toBe(['storage.local', 'storage.s3', 'storage.azure'])
        ->and($drivers('esign'))->toBe(['esign.lawtrust'])
        ->and($types['payment']['modes'])->toBeTrue()
        ->and($types['storage']['single'])->toBeTrue();
});

it('stores secrets encrypted and never returns them', function (): void {
    signInStaff($this);

    $data = $this->putJson($this->base.'/payment.paystack', [
        'values' => ['public_key' => 'pk_test_abc', 'secret_key' => 'sk_test_supersecret_1234'],
    ])->assertOk()
        ->assertJsonPath('data.values.public_key', 'pk_test_abc')
        ->assertJsonPath('data.secrets.secret_key', '••••1234')
        ->assertJsonPath('data.mode', 'sandbox')
        ->assertJsonPath('data.enabled', false)
        ->assertJsonPath('data.complete', true)
        ->json('data');

    $raw = asPlatform(fn () => DB::table('integrations')->where('id', $data['id'])->value('credentials'));
    expect($raw)->not->toContain('sk_test_supersecret')
        ->and(json_encode($this->getJson($this->base)->json()))->not->toContain('supersecret');

    // Leaving a secret out keeps the saved one; null clears it.
    $this->putJson($this->base.'/payment.paystack', ['values' => ['public_key' => 'pk_test_new']])
        ->assertOk()->assertJsonPath('data.secrets.secret_key', '••••1234')->assertJsonPath('data.values.public_key', 'pk_test_new');
    $this->putJson($this->base.'/payment.paystack', ['values' => ['secret_key' => null]])
        ->assertOk()->assertJsonPath('data.secrets.secret_key', null)->assertJsonPath('data.complete', false);

    $audit = asPlatform(fn () => AuditLog::query()->where('action', 'integration.created')->firstOrFail());
    expect(json_encode($audit->changes))->not->toContain('supersecret')->toContain('[redacted]');
});

it('enables, disables and orders drivers', function (): void {
    signInStaff($this);
    Http::fake(['api.bulksms.com/v1/profile' => Http::response(['id' => '1'])]);

    $this->putJson($this->base.'/sms.clickatell', ['enabled' => true])->assertStatus(422)
        ->assertJsonPath('error.code', 'incomplete')->assertJsonStructure(['errors' => ['values.api_key']]);

    $click = $this->putJson($this->base.'/sms.clickatell', ['values' => ['api_key' => 'ck-1'], 'enabled' => true])
        ->assertOk()->assertJsonPath('data.enabled', true)->json('data.id');
    $bulk = $this->putJson($this->base.'/sms.bulksms', ['values' => ['token_id' => 'a', 'token_secret' => 'b'], 'enabled' => true])
        ->assertOk()->json('data.id');

    expect(asPlatform(fn () => Integration::query()->active('sms')->pluck('id')->all()))->toBe([$click, $bulk]);

    $this->putJson($this->base.'/order', ['type' => 'sms', 'ids' => [$bulk, $click]])->assertNoContent();
    expect(asPlatform(fn () => Integration::query()->active('sms')->pluck('id')->all()))->toBe([$bulk, $click]);

    $this->postJson($this->base."/{$bulk}/disable")->assertOk()->assertJsonPath('data.enabled', false);
    expect(asPlatform(fn () => Integration::query()->active('sms')->pluck('id')->all()))->toBe([$click]);

    $this->putJson($this->base.'/order', ['type' => 'payment', 'ids' => [$click]])->assertStatus(422);
});

it('keeps only one storage driver on, after a passing connection test', function (): void {
    signInStaff($this);

    $local = $this->putJson($this->base.'/storage.local', ['values' => ['root' => 'files-test'], 'enabled' => true])
        ->assertOk()->assertJsonPath('data.enabled', true)->assertJsonPath('data.last_test.ok', true)->json('data.id');

    // S3 with an unreachable endpoint fails its test and stays off; local stays on.
    $this->putJson($this->base.'/storage.s3', ['values' => [
        'key' => 'k', 'secret' => 's', 'bucket' => 'b', 'endpoint' => 'http://127.0.0.1:1', 'path_style' => true,
    ], 'enabled' => true])->assertStatus(422)->assertJsonPath('error.code', 'test_failed');

    expect(asPlatform(fn () => Integration::query()->active('storage')->pluck('id')->all()))->toBe([$local]);

    $this->postJson($this->base."/{$local}/test")->assertOk()->assertJsonPath('ok', true);
});

it('rejects unknown drivers and bad values', function (): void {
    signInStaff($this);

    $this->putJson($this->base.'/payment.unknown', ['values' => []])->assertStatus(422)->assertJsonPath('error.code', 'unknown_driver');
    $this->putJson($this->base.'/email.smtp', ['values' => ['port' => 'abc', 'encryption' => 'rot13']])->assertStatus(422)
        ->assertJsonStructure(['errors' => ['values.port', 'values.encryption']]);
    $this->putJson($this->base.'/storage.local', ['values' => ['root' => '../../etc'], 'enabled' => true])->assertStatus(422);
});
