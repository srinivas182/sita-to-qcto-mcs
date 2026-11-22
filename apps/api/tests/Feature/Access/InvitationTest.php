<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Modules\Access\Models\Invitation;
use Modules\Access\Notifications\InvitationNotification;
use Modules\Access\Services\AccessService;
use Modules\Identity\Models\User;
use Modules\Identity\Otp\LogOtpChannel;
use Modules\Tenancy\Models\Centre;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\TestDevice;

beforeEach(function (): void {
    Notification::fake();
    $this->tym = makeProvider('tym', ['trading_name' => 'Training Young Minds']);
    $this->other = makeProvider('other');
    $this->centre = asPlatform(fn () => Centre::factory()->create(['tenant_id' => $this->tym->id, 'name' => 'Soweto']));
    $this->owner = makeMember($this->tym, ['tp-owner'], ['email' => 'sekal@tym.co.za', 'password' => 'correct horse battery staple']);
    $this->web = webOrigin('tym.skills.test');

    // Owner signs in and sets up MFA (mandatory for owners).
    $this->withHeaders($this->web)->postJson('https://tym.skills.test/v1/auth/login', ['email' => 'sekal@tym.co.za', 'password' => 'correct horse battery staple']);
    $secret = $this->withHeaders($this->web)->postJson('https://tym.skills.test/v1/auth/mfa/totp/enable')->json('secret');
    $this->withHeaders($this->web)->postJson('https://tym.skills.test/v1/auth/mfa/totp/confirm', ['code' => app(Google2FA::class)->getCurrentOtp($secret)]);
});

function invite(object $test, array $body): TestResponse
{
    return $test->withHeaders($test->web)->postJson("https://tym.skills.test/v1/tenants/{$test->tym->id}/invitations", $body);
}

function tokenFromMail(): string
{
    $url = '';
    Notification::assertSentOnDemand(InvitationNotification::class, function (InvitationNotification $n) use (&$url): bool {
        $url = $n->url;

        return true;
    });

    return basename($url);
}

it('invites a facilitator by email and the invitee joins with a password', function (): void {
    invite($this, ['email' => 'Lwazi@Example.com', 'first_name' => 'Lwazi', 'role' => 'facilitator', 'centre_id' => $this->centre->id])
        ->assertCreated()->assertJsonPath('email', 'lwazi@example.com');

    $token = tokenFromMail();
    $this->getJson("https://tym.skills.test/v1/invitations/{$token}")
        ->assertOk()->assertJson(['tenant' => 'Training Young Minds', 'role' => 'facilitator', 'role_label' => 'Facilitator']);

    $this->app['auth']->forgetGuards();
    $guest = webOrigin('tym.skills.test');
    $this->flushSession();
    $this->withHeaders($guest)->postJson("https://tym.skills.test/v1/invitations/{$token}/accept", [
        'first_name' => 'Lwazi', 'last_name' => 'Dube', 'password' => 'a long passphrase 2026',
    ])->assertCreated()->assertJson(['accepted' => true, 'mode' => 'session']);

    $user = asPlatform(fn () => User::query()->where('email', 'lwazi@example.com')->firstOrFail());
    asTenant($this->tym, fn () => expect(app(AccessService::class)->can($user, 'learning.deliver', $this->centre->id))->toBeTrue());

    $this->getJson("https://tym.skills.test/v1/invitations/{$token}")->assertNotFound();
});

it('links an existing person from another provider instead of duplicating them', function (): void {
    $person = makeMember($this->other, ['learner'], ['email' => 'zanele@example.com']);

    invite($this, ['email' => 'zanele@example.com', 'role' => 'learner'])->assertCreated();
    $this->flushSession()->flushHeaders();
    $this->postJson('https://tym.skills.test/v1/invitations/'.tokenFromMail().'/accept', ['device' => (new TestDevice)->payload()])
        ->assertCreated()->assertJson(['mode' => 'token']);

    expect(asPlatform(fn () => User::query()->where('email', 'zanele@example.com')->count()))->toBe(1)
        ->and(asPlatform(fn () => $person->memberships()->count()))->toBe(2);
});

it('sends a WhatsApp invitation to a phone number', function (): void {
    invite($this, ['phone' => '082 555 0101', 'role' => 'learner'])->assertCreated()->assertJsonPath('phone', '+27825550101');

    expect(end(LogOtpChannel::$sent)['message'])->toContain('/invite/');
});

it('stops a centre manager from inviting above their level', function (): void {
    $manager = makeMember($this->tym, [['centre-manager', $this->centre->id]], ['email' => 'thandi@tym.co.za', 'password' => 'correct horse battery staple']);
    $this->withHeaders($this->web)->postJson('https://tym.skills.test/v1/auth/logout');
    $this->withHeaders($this->web)->postJson('https://tym.skills.test/v1/auth/login', ['email' => 'thandi@tym.co.za', 'password' => 'correct horse battery staple']);

    invite($this, ['email' => 'x@example.com', 'role' => 'tp-owner'])->assertForbidden()->assertJsonPath('error.code', 'role_delegation');
    invite($this, ['email' => 'y@example.com', 'role' => 'assessor', 'centre_id' => $this->centre->id])->assertCreated();
});

it('refuses invitations into another provider', function (): void {
    $this->withHeaders($this->web)->postJson("https://tym.skills.test/v1/tenants/{$this->other->id}/invitations", ['email' => 'x@example.com', 'role' => 'learner'])
        ->assertNotFound();
});

it('expires and revokes invitations', function (): void {
    $id = invite($this, ['email' => 'late@example.com', 'role' => 'learner'])->json('id');
    $token = tokenFromMail();

    $this->withHeaders($this->web)->getJson('https://tym.skills.test/v1/invitations')->assertOk()->assertJsonCount(1, 'data');
    $this->withHeaders($this->web)->deleteJson("https://tym.skills.test/v1/invitations/{$id}")->assertNoContent();
    $this->getJson("https://tym.skills.test/v1/invitations/{$token}")->assertNotFound()->assertJsonPath('error.code', 'invitation_invalid');

    invite($this, ['email' => 'late2@example.com', 'role' => 'learner']);
    $this->travel(8)->days();
    expect(asTenant($this->tym, fn () => Invitation::query()->get()->filter->isOpen()->count()))->toBe(0);
});

it('requires an email or phone', function (): void {
    invite($this, ['role' => 'learner'])->assertStatus(422)->assertJsonValidationErrors('email');
});
