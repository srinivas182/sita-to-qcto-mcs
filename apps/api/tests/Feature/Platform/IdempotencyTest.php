<?php

declare(strict_types=1);

use Modules\Identity\Models\Consent;
use Tests\Support\TestDevice;

beforeEach(function (): void {
    $this->tym = makeProvider('tym');
    makeMember($this->tym, ['learner'], ['email' => 'learner@tym.co.za', 'password' => 'correct horse battery staple']);
    $this->token = $this->postJson('https://tym.skills.test/v1/auth/login', [
        'email' => 'learner@tym.co.za', 'password' => 'correct horse battery staple', 'device' => (new TestDevice)->payload(),
    ])->json('access_token');
    $this->headers = fn (string $key) => ['Authorization' => 'Bearer '.$this->token, 'Idempotency-Key' => $key];
});

it('replays the first response for a retried write instead of repeating it', function (): void {
    $first = $this->postJson('https://tym.skills.test/v1/me/consents', ['purpose' => 'privacy', 'version' => '2026-11'], ($this->headers)('outbox-0001-abcdef'))
        ->assertCreated();

    // Withdraw in between: a real repeat would create a new consent.
    $this->deleteJson('https://tym.skills.test/v1/me/consents/privacy', [], ['Authorization' => 'Bearer '.$this->token])->assertOk();

    $this->postJson('https://tym.skills.test/v1/me/consents', ['purpose' => 'privacy', 'version' => '2026-11'], ($this->headers)('outbox-0001-abcdef'))
        ->assertCreated()
        ->assertHeader('Idempotent-Replayed', 'true')
        ->assertExactJson($first->json());

    expect(asTenant($this->tym, fn () => Consent::query()->count()))->toBe(1);
});

it('rejects a reused key with a different body', function (): void {
    $this->postJson('https://tym.skills.test/v1/me/consents', ['purpose' => 'privacy', 'version' => '1'], ($this->headers)('outbox-0002-abcdef'))->assertCreated();

    $this->postJson('https://tym.skills.test/v1/me/consents', ['purpose' => 'terms', 'version' => '1'], ($this->headers)('outbox-0002-abcdef'))
        ->assertStatus(422)->assertJsonPath('error.code', 'idempotency_key_reused');
});

it('validates the key and ignores reads', function (): void {
    $this->postJson('https://tym.skills.test/v1/me/consents', ['purpose' => 'privacy', 'version' => '1'], ($this->headers)('bad key!'))
        ->assertStatus(422)->assertJsonPath('error.code', 'idempotency_key_invalid');

    $this->getJson('https://tym.skills.test/v1/me', ($this->headers)('bad key!'))->assertOk();
});

it('scopes keys to the caller', function (): void {
    makeMember($this->tym, ['learner'], ['email' => 'other@tym.co.za', 'password' => 'correct horse battery staple']);
    $other = $this->postJson('https://tym.skills.test/v1/auth/login', [
        'email' => 'other@tym.co.za', 'password' => 'correct horse battery staple', 'device' => (new TestDevice)->payload(),
    ])->json('access_token');

    $this->postJson('https://tym.skills.test/v1/me/consents', ['purpose' => 'privacy', 'version' => '1'], ($this->headers)('shared-key-123'))->assertCreated();
    $this->postJson('https://tym.skills.test/v1/me/consents', ['purpose' => 'privacy', 'version' => '1'], ['Authorization' => 'Bearer '.$other, 'Idempotency-Key' => 'shared-key-123'])
        ->assertCreated()->assertHeaderMissing('Idempotent-Replayed');

    expect(asTenant($this->tym, fn () => Consent::query()->count()))->toBe(2);
});
