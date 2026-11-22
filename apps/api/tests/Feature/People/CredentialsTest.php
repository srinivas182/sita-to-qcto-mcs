<?php

declare(strict_types=1);

require_once __DIR__.'/helpers.php';

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Modules\Identity\Models\User;
use Modules\People\Models\StaffCredential;
use Modules\People\Notifications\CredentialExpiring;

beforeEach(function (): void {
    Notification::fake();
    $this->tym = makeProvider('tym', ['trading_name' => 'Training Young Minds']);
    $this->q = catalogueEntry();
    $this->other = catalogueEntry('National Certificate: Building and Civil Construction', '65409');
    $this->sipho = makeMember($this->tym, ['assessor'], ['first_name' => 'Sipho', 'email' => 'sipho@tym.test', 'password' => 'correct horse battery staple']);
});

it('lets staff add their own registration, which someone else verifies', function (): void {
    signInAs($this, 'sipho@tym.test');
    $letter = registrationLetter($this->tym, $this->sipho->id);
    $id = $this->postJson(TYM_V1.'/me/credentials', [
        'role' => 'assessor', 'body' => 'CETA', 'registration_number' => 'CETA/ASS/2024/0193', 'qualification_ids' => [$this->q->id],
        'starts_on' => '2024-03-01', 'expires_on' => now()->addYears(2)->toDateString(), 'file_id' => $letter,
    ])->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');

    // Not by themselves, even with the right permission.
    makeMember($this->tym, ['tp-owner'], ['email' => 'self-owner@tym.test', 'password' => 'correct horse battery staple']);
    $this->postJson(TYM_V1."/credentials/{$id}/verify")->assertForbidden();

    signInOwner($this, $this->tym);
    $this->getJson(TYM_V1.'/people/credentials')->assertOk()->assertJsonPath('data.0.person', 'Sipho '.$this->sipho->last_name)->assertJsonPath('counts.pending', 1);
    $this->postJson(TYM_V1."/credentials/{$id}/verify")->assertOk()->assertJsonPath('data.state', 'ok');

    // Changing the scope sends it back for checking.
    $this->patchJson(TYM_V1."/credentials/{$id}", ['qualification_ids' => [$this->q->id, $this->other->id]])->assertOk()->assertJsonPath('data.status', 'pending');
});

it('refuses to verify without the letter, and yourself', function (): void {
    signInOwner($this, $this->tym);
    $owner = asPlatform(fn () => User::query()->where('email', 'owner@tym.test')->sole());
    $id = $this->postJson(TYM_V1."/people/{$this->sipho->id}/credentials", ['role' => 'assessor', 'body' => 'CETA', 'registration_number' => 'A-1', 'qualification_ids' => [$this->q->id]])->assertCreated()->json('data.id');
    $this->postJson(TYM_V1."/credentials/{$id}/verify")->assertStatus(422)->assertJsonFragment(['Upload the registration letter before verifying.']);

    $mine = $this->postJson(TYM_V1.'/me/credentials', ['role' => 'facilitator', 'body' => 'CETA', 'registration_number' => 'F-1', 'qualification_ids' => [$this->q->id]])->json('data.id');
    asTenant($this->tym, fn () => StaffCredential::query()->whereKey($mine)->update(['file_id' => registrationLetter($this->tym, $owner->id)]));
    $this->postJson(TYM_V1."/credentials/{$mine}/verify")->assertStatus(422)->assertJsonFragment(['Someone else must verify your own registration.']);
});

it('only lets someone moderate where they are also a verified assessor', function (): void {
    signInOwner($this, $this->tym);
    $letter = registrationLetter($this->tym, $this->sipho->id);
    $mod = $this->postJson(TYM_V1."/people/{$this->sipho->id}/credentials", ['role' => 'moderator', 'body' => 'CETA', 'registration_number' => 'M-1', 'qualification_ids' => [$this->q->id], 'file_id' => $letter])->json('data.id');
    $this->postJson(TYM_V1."/credentials/{$mod}/verify")->assertStatus(422)->assertJsonFragment(['A moderator must also be a verified assessor for: Occupational Certificate: Bricklayer.']);

    $ass = $this->postJson(TYM_V1."/people/{$this->sipho->id}/credentials", ['role' => 'assessor', 'body' => 'CETA', 'registration_number' => 'A-1', 'qualification_ids' => [$this->q->id], 'file_id' => $letter])->json('data.id');
    $this->postJson(TYM_V1."/credentials/{$ass}/verify")->assertOk();
    $this->postJson(TYM_V1."/credentials/{$mod}/verify")->assertOk();
});

it('answers who may assess what, with the reason', function (): void {
    signInOwner($this, $this->tym);
    $check = fn (string $role, string $q, ?string $on = null) => $this->getJson(TYM_V1."/people/{$this->sipho->id}/eligibility?role={$role}&qualification_id={$q}".($on ? "&on={$on}" : ''))->json('data');

    expect($check('assessor', $this->q->id))->toMatchArray(['ok' => false, 'reason' => 'No Assessor registration recorded.']);
    $letter = registrationLetter($this->tym, $this->sipho->id);
    $id = $this->postJson(TYM_V1."/people/{$this->sipho->id}/credentials", ['role' => 'assessor', 'body' => 'CETA', 'registration_number' => 'A-1',
        'qualification_ids' => [$this->q->id], 'expires_on' => '2027-06-30', 'file_id' => $letter])->json('data.id');
    expect($check('assessor', $this->q->id)['reason'])->toBe('Their Assessor registration is waiting to be verified.');
    $this->postJson(TYM_V1."/credentials/{$id}/verify")->assertOk();

    expect($check('assessor', $this->q->id))->toMatchArray(['ok' => true, 'reason' => 'Registered Assessor (CETA A-1) until 30 Jun 2027.'])
        ->and($check('assessor', $this->other->id)['reason'])->toBe('Their Assessor registration does not cover this qualification.')
        ->and($check('assessor', $this->q->id, '2027-07-01')['reason'])->toBe('Their Assessor registration has expired or has not started.')
        ->and($check('moderator', $this->q->id)['ok'])->toBeFalse();
});

it('warns holder and owners 90, 60 and 30 days before a registration ends', function (): void {
    signInOwner($this, $this->tym);
    $letter = registrationLetter($this->tym, $this->sipho->id);
    $id = $this->postJson(TYM_V1."/people/{$this->sipho->id}/credentials", ['role' => 'assessor', 'body' => 'CETA', 'registration_number' => 'A-1',
        'qualification_ids' => [$this->q->id], 'expires_on' => now()->addDays(75)->toDateString(), 'file_id' => $letter])->json('data.id');
    $this->postJson(TYM_V1."/credentials/{$id}/verify")->assertOk();

    Artisan::call('credentials:expiry-alerts');
    Notification::assertSentOnDemand(CredentialExpiring::class, fn ($n, $c, $to) => $to->routes['mail'] === 'sipho@tym.test' && $n->toHolder && $n->days === 75);
    Notification::assertSentOnDemand(CredentialExpiring::class, fn ($n) => ! $n->toHolder);
    $count = count(Notification::sentNotifications());
    Artisan::call('credentials:expiry-alerts');
    expect(count(Notification::sentNotifications()))->toBe($count);

    // Renewed: the new expiry restarts reminders (and needs checking again).
    $this->patchJson(TYM_V1."/credentials/{$id}", ['expires_on' => now()->addYears(3)->toDateString()])->assertOk()->assertJsonPath('data.status', 'pending');
    expect(asTenant($this->tym, fn () => StaffCredential::query()->findOrFail($id)->alerted_for))->toBeNull();
});

it('keeps registrations inside the provider', function (): void {
    $other = makeProvider('other');
    signInOwner($this, $this->tym);
    $this->postJson(TYM_V1."/people/{$this->sipho->id}/credentials", ['role' => 'assessor', 'body' => 'CETA', 'registration_number' => 'A-1', 'qualification_ids' => [$this->q->id]])->assertCreated();
    expect(asTenant($other, fn () => StaffCredential::query()->count()))->toBe(0);

    // A learner sees neither the register nor anyone's credentials.
    makeMember($this->tym, ['learner'], ['email' => 'learner@tym.test', 'password' => 'correct horse battery staple']);
    signInAs($this, 'learner@tym.test');
    $this->getJson(TYM_V1.'/people/credentials')->assertForbidden();
    $this->getJson(TYM_V1."/people/{$this->sipho->id}/credentials")->assertForbidden();
});
