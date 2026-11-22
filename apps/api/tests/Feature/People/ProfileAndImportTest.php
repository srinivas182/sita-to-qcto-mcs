<?php

declare(strict_types=1);

require_once __DIR__.'/helpers.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Modules\Access\Models\Invitation;
use Modules\Access\Services\InvitationService;
use Modules\Identity\Models\User;
use Modules\Tenancy\Models\Centre;

beforeEach(function (): void {
    Notification::fake();
    $this->tym = makeProvider('tym');
    $this->lebo = makeMember($this->tym, ['learner'], ['first_name' => 'Lebo', 'email' => 'lebo@tym.test', 'password' => 'correct horse battery staple']);
});

it('checks SA ID numbers, takes the date of birth from them and keeps them unique', function (): void {
    signInAs($this, 'lebo@tym.test');
    $this->patchJson(TYM_V1.'/me/profile', ['id_type' => 'sa_id', 'id_number' => '8001015009088'])->assertStatus(422)->assertJsonValidationErrors('id_number');
    $this->patchJson(TYM_V1.'/me/profile', ['id_type' => 'sa_id', 'id_number' => '800101 5009 087', 'postal_code' => '1804', 'province' => 'Gauteng'])
        ->assertOk()->assertJsonPath('data.dob', '1980-01-01')->assertJsonPath('data.id_number', '800101•••••87');

    // Once recorded, only the provider can change it.
    $this->patchJson(TYM_V1.'/me/profile', ['id_type' => 'sa_id', 'id_number' => '9202204720083'])->assertStatus(422);

    // Nobody else on the platform may hold the same number.
    $other = makeMember($this->tym, ['learner'], ['email' => 'other@tym.test', 'password' => 'correct horse battery staple']);
    signInOwner($this, $this->tym);
    $this->patchJson(TYM_V1."/people/{$other->id}/profile", ['id_type' => 'sa_id', 'id_number' => '8001015009087'])->assertStatus(422)
        ->assertJsonFragment(['This ID number is already registered to another person. Contact support to resolve it.']);
});

it('keeps equity data only with consent, encrypted, and erases it when consent is withdrawn', function (): void {
    signInAs($this, 'lebo@tym.test');
    $this->patchJson(TYM_V1.'/me/profile', ['equity' => ['race' => 'african']])->assertStatus(422)->assertJsonValidationErrors('equity_consent');
    $this->patchJson(TYM_V1.'/me/profile', ['id_type' => 'sa_id', 'id_number' => '8001015009087'])->assertOk();

    $res = $this->patchJson(TYM_V1.'/me/profile', ['equity_consent' => true, 'equity' => ['gender' => 'female', 'race' => 'african', 'disability' => false]])->assertOk();
    expect($res->json('data.equity'))->toMatchArray(['gender' => 'female', 'race' => 'african'])
        ->and($res->json('warnings.0'))->toContain('differs from the one on the ID number');
    $raw = asTenant($this->tym, fn () => (string) DB::table('person_profiles')->value('equity'));
    expect($raw)->not->toContain('african');

    $this->patchJson(TYM_V1.'/me/profile', ['equity_consent' => false])->assertOk()->assertJsonPath('data.equity_consented', false)->assertJsonPath('data.equity', null);
    expect(asTenant($this->tym, fn () => DB::table('person_profiles')->value('equity')))->toBeNull();
});

it('imports people after a dry run, and applies ID numbers when they accept', function (): void {
    asTenant($this->tym, fn () => Centre::query()->create(['name' => 'Soweto Campus']));
    signInOwner($this, $this->tym);
    $csv = "first_name,last_name,email,phone,role,centre,id_number\n"
        ."Ayanda,Dlamini,ayanda@example.co.za,,Learner,Soweto Campus,9202204720083\n"
        ."Bongani,Zulu,,082 555 0199,assessor,,\n"
        ."Carol,Smith,not-an-email,,Wizard,,123\n"
        ."Dineo,Mokoena,ayanda@example.co.za,,learner,,\n";

    $dry = $this->postJson(TYM_V1.'/people/import', ['csv' => $csv])->assertOk();
    expect($dry->json('ready'))->toBe(1)->and($dry->json('problems'))->toBe(3)->and($dry->json('sent'))->toBe(0);
    $rows = collect($dry->json('rows'))->keyBy('line');
    expect($rows[3]['problems'])->toContain('This role needs a centre.')
        ->and($rows[4]['problems'])->toContain('Unknown role "Wizard".')->toContain('The email address is not valid.')
        ->and($rows[4]['problems'])->toContain('The ID number is not a valid South African ID (check the 13 digits).')
        ->and($rows[5]['problems'])->toContain('Same email as line 2.');

    // Nothing goes out while any row has a problem.
    $this->postJson(TYM_V1.'/people/import', ['csv' => $csv, 'send' => true])->assertOk()->assertJsonPath('sent', 0);

    $clean = "first_name,last_name,email,role,centre,id_number\nAyanda,Dlamini,ayanda@example.co.za,Learner,Soweto Campus,9202204720083\nBongani,Zulu,bongani@example.co.za,Assessor,Soweto Campus,\n";
    $this->postJson(TYM_V1.'/people/import', ['csv' => $clean, 'send' => true])->assertOk()->assertJsonPath('sent', 2);

    $invitation = asTenant($this->tym, fn () => Invitation::query()->where('email', 'ayanda@example.co.za')->sole());
    expect($invitation->id_number)->toBe('9202204720083');
    $token = 'known-token-value-'.str_repeat('x', 30);
    asTenant($this->tym, fn () => $invitation->forceFill(['token_hash' => hash('sha256', $token)])->save());
    $user = asTenant($this->tym, fn () => app(InvitationService::class)->accept($token, null, ['first_name' => 'Ayanda', 'last_name' => 'Dlamini', 'password' => 'correct horse battery staple']));
    expect(asPlatform(fn () => User::query()->findOrFail($user->id)->dob?->toDateString()))->toBe('1992-02-20');
});
