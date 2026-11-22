<?php

declare(strict_types=1);

require_once __DIR__.'/../People/helpers.php';

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Modules\Employers\Models\Employer;
use Modules\Employers\Models\EmployerSite;
use Modules\Employers\Notifications\WorkplaceApprovalExpiring;
use Modules\Qualifications\Models\QualificationModule;

beforeEach(function (): void {
    Notification::fake();
    $this->tym = makeProvider('tym', ['trading_name' => 'Training Young Minds']);
    $this->q = catalogueEntry();
    signInOwner($this, $this->tym);
});

function addEmployer(object $test): string
{
    return $test->postJson(TYM_V1.'/employers', [
        'legal_name' => 'Mabaso Builders (Pty) Ltd', 'registration_number' => '2015/ 123456 /07', 'sdl_number' => 'l123456789',
        'seta' => 'CETA', 'bbbee_level' => '2', 'contact_name' => 'Bheki Mabaso', 'contact_phone' => '082 555 0101',
    ])->assertCreated()->assertJsonPath('data.registration_number', '2015/123456/07')->assertJsonPath('data.sdl_number', 'L123456789')
        ->assertJsonPath('data.contact_phone', '+27825550101')->json('data.id');
}

it('checks employer identifiers', function (): void {
    $this->postJson(TYM_V1.'/employers', ['legal_name' => 'X Builders', 'registration_number' => '2015-1'])->assertStatus(422)->assertJsonValidationErrors('registration_number');
    $this->postJson(TYM_V1.'/employers', ['legal_name' => 'X Builders', 'sdl_number' => '123'])->assertStatus(422)->assertJsonValidationErrors('sdl_number');
    $this->postJson(TYM_V1.'/employers', ['legal_name' => 'X Builders', 'seta' => 'Not a SETA'])->assertStatus(422)->assertJsonValidationErrors('seta');
    addEmployer($this);
    $this->postJson(TYM_V1.'/employers', ['legal_name' => 'Duplicate', 'registration_number' => '2015/123456/07'])->assertStatus(422)->assertJsonValidationErrors('registration_number');
});

it('works out each workplace\'s capacity from its approval and mentors', function (): void {
    $id = addEmployer($this);
    $site = $this->postJson(TYM_V1."/employers/{$id}/sites", [
        'name' => 'Soweto yard', 'city' => 'Soweto', 'province' => 'Gauteng', 'latitude' => -26.26, 'longitude' => 27.86,
        'approval_number' => 'CETA/WPA/2026/0091', 'approval_body' => 'CETA', 'approved_on' => '2026-02-01', 'approval_expires_on' => now()->addYears(2)->toDateString(),
        'approved_qualification_ids' => [$this->q->id],
    ])->assertCreated()->json('data.sites.0');
    expect($site['approval_state'])->toBe('approved')->and($site['mentor_ratio'])->toBe(10);

    // No mentors yet: no capacity.
    expect($this->getJson(TYM_V1."/employers/{$id}")->json('data.capacity.0.capacity'))->toBe(0);

    $mentor = ['name' => 'Bheki Mabaso', 'designation' => 'Site foreman', 'years_experience' => 14, 'highest_qualification' => 'Bricklayer trade certificate', 'qualification_nqf_level' => 4];
    $this->postJson(TYM_V1."/employers/{$id}/mentors", $mentor + ['site_ids' => [$site['id']]])->assertCreated();
    $this->postJson(TYM_V1."/employers/{$id}/mentors", $mentor + ['name' => 'Zodwa Mabaso', 'site_ids' => [$site['id']]])->assertCreated();
    expect($this->getJson(TYM_V1."/employers/{$id}")->json('data.capacity.0'))->toMatchArray(['mentors' => 2, 'ratio' => 10, 'capacity' => 20]);

    // A stricter ratio for this qualification at this site.
    $this->patchJson(TYM_V1."/employers/{$id}/sites/{$site['id']}", ['ratio_overrides' => [$this->q->id => 5]])->assertOk();
    expect($this->getJson(TYM_V1."/employers/{$id}")->json('data.capacity.0.capacity'))->toBe(10);

    // An expired approval leaves no capacity.
    $this->patchJson(TYM_V1."/employers/{$id}/sites/{$site['id']}", ['approved_on' => '2020-01-01', 'approval_expires_on' => '2024-01-01'])->assertOk();
    $show = $this->getJson(TYM_V1."/employers/{$id}")->json('data');
    expect($show['sites'][0]['approval_state'])->toBe('expired')->and($show['capacity'][0]['capacity'])->toBe(0);
    expect($this->getJson(TYM_V1.'/employers')->json('data.0.approval'))->toBe('expired');
});

it('keeps mentors to the employer\'s own sites and shows the curriculum\'s workplace criteria', function (): void {
    $a = addEmployer($this);
    $b = $this->postJson(TYM_V1.'/employers', ['legal_name' => 'Other Co'])->json('data.id');
    $otherSite = $this->postJson(TYM_V1."/employers/{$b}/sites", ['name' => 'Elsewhere'])->json('data.sites.0.id');
    $this->postJson(TYM_V1."/employers/{$a}/mentors", ['name' => 'Xolani Dube', 'designation' => 'Foreman', 'years_experience' => 3, 'site_ids' => [$otherSite]])
        ->assertStatus(422)->assertJsonValidationErrors('site_ids');

    asPlatform(fn () => QualificationModule::query()->create(['qualification_id' => $this->q->id, 'kind' => 'WM', 'group' => 'compulsory', 'code' => '641201001-WM-01',
        'title' => 'Laying bricks on site', 'credits' => 40, 'workplace_criteria' => 'A qualified bricklayer with 3 years\' experience supervises the learner.']));
    $this->postJson(TYM_V1."/employers/{$a}/sites", ['name' => 'Yard', 'approval_number' => 'W-1', 'approval_body' => 'CETA', 'approved_qualification_ids' => [$this->q->id]])->assertCreated();
    expect($this->getJson(TYM_V1."/employers/{$a}")->json('data.workplace_criteria.0'))->toMatchArray(['code' => '641201001-WM-01', 'criteria' => 'A qualified bricklayer with 3 years\' experience supervises the learner.']);
});

it('links people who act for the employer', function (): void {
    $id = addEmployer($this);
    $bheki = makeMember($this->tym, ['host-employer'], ['email' => 'bheki@mabaso.test']);
    $stranger = makeMember(makeProvider('other'), [], ['email' => 'stranger@other.test']);
    $this->postJson(TYM_V1."/employers/{$id}/members", ['user_id' => $stranger->id])->assertStatus(422);
    $this->postJson(TYM_V1."/employers/{$id}/members", ['user_id' => $bheki->id])->assertCreated()->assertJsonPath('data.members.0.email', 'bheki@mabaso.test');
    $this->deleteJson(TYM_V1."/employers/{$id}/members/{$bheki->id}")->assertOk()->assertJsonCount(0, 'data.members');
});

it('warns owners 90, 60 and 30 days before a workplace approval ends', function (): void {
    $id = addEmployer($this);
    $site = $this->postJson(TYM_V1."/employers/{$id}/sites", ['name' => 'Yard', 'approval_number' => 'W-1', 'approval_body' => 'CETA',
        'approval_expires_on' => now()->addDays(40)->toDateString()])->json('data.sites.0.id');
    Artisan::call('employers:approval-alerts');
    Notification::assertSentOnDemand(WorkplaceApprovalExpiring::class, fn ($n) => $n->days === 40 && $n->employer === 'Mabaso Builders (Pty) Ltd');
    $count = count(Notification::sentNotifications());
    Artisan::call('employers:approval-alerts');
    expect(count(Notification::sentNotifications()))->toBe($count);

    $this->patchJson(TYM_V1."/employers/{$id}/sites/{$site}", ['approval_expires_on' => now()->addYears(3)->toDateString()])->assertOk();
    expect(asTenant($this->tym, fn () => EmployerSite::query()->findOrFail($site)->alerted_for))->toBeNull();
});

it('keeps employers inside the provider and away from learners', function (): void {
    addEmployer($this);
    $other = makeProvider('other');
    expect(asTenant($other, fn () => Employer::query()->count()))->toBe(0)
        ->and(asTenant($other, fn () => DB::table('employer_sites')->count()))->toBe(0);
    makeMember($this->tym, ['learner'], ['email' => 'learner@tym.test', 'password' => 'correct horse battery staple']);
    signInAs($this, 'learner@tym.test');
    $this->getJson(TYM_V1.'/employers')->assertForbidden();
});
