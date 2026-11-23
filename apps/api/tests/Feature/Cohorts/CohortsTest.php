<?php

declare(strict_types=1);

require_once __DIR__.'/../People/helpers.php';

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Modules\Agreements\Models\Agreement;
use Modules\Agreements\Models\Template;
use Modules\Cohorts\Models\Cohort;
use Modules\Cohorts\Notifications\CohortAtRisk;
use Modules\Employers\Models\Employer;
use Modules\Employers\Models\EmployerSite;
use Modules\Employers\Models\Mentor;
use Modules\People\Models\StaffCredential;
use Modules\Qualifications\Models\CentreQualification;
use Modules\Qualifications\Models\Programme;
use Modules\Qualifications\Models\QualificationModule;
use Modules\Tenancy\Models\Centre;

beforeEach(function (): void {
    Notification::fake();
    $this->tym = makeProvider('tym', ['trading_name' => 'Training Young Minds']);
    $this->q = catalogueEntry();
    asPlatform(fn () => QualificationModule::query()->create(['qualification_id' => $this->q->id, 'kind' => 'WM', 'group' => 'compulsory', 'code' => 'WM-01', 'title' => 'Laying bricks on site', 'credits' => 40]));
    signInOwner($this, $this->tym);

    $people = [];
    foreach (['Sipho Ndlovu' => ['facilitator', 'assessor'], 'Lindiwe Mthembu' => ['assessor', 'moderator'], 'Thabo Facilitator' => ['facilitator']] as $name => $roles) {
        [$first, $last] = explode(' ', $name);
        $u = makeMember($this->tym, ['facilitator'], ['email' => strtolower($first).'@tym.test', 'first_name' => $first, 'last_name' => $last]);
        $people[$first] = $u;
        asTenant($this->tym, function () use ($u, $roles): void {
            foreach ($roles as $role) {
                StaffCredential::query()->create(['user_id' => $u->id, 'role' => $role, 'body' => 'CETA', 'registration_number' => strtoupper($role).'-'.Str::random(4),
                    'qualification_ids' => [$this->q->id], 'starts_on' => now()->subYear(), 'expires_on' => now()->addYears(3)])->forceFill(['status' => 'verified'])->save();
            }
        });
    }
    $this->people = $people;

    [$this->centre, $this->employer, $this->site] = asTenant($this->tym, function () {
        Programme::query()->create(['qualification_id' => $this->q->id, 'active' => true, 'modes' => ['contact', 'blended']]);
        $centre = Centre::query()->create(['name' => 'Soweto Campus', 'accreditation_number' => 'QCTO/SDP/1', 'accreditation_expires_on' => now()->addYears(3)]);
        CentreQualification::query()->create(['centre_id' => $centre->id, 'qualification_id' => $this->q->id, 'expires_on' => now()->addYears(3)]);
        $e = Employer::query()->create(['legal_name' => 'Mabaso Builders (Pty) Ltd']);
        $s = EmployerSite::query()->create(['employer_id' => $e->id, 'name' => 'Soweto yard', 'approval_number' => 'CETA/WPA/1', 'approval_body' => 'CETA',
            'approved_on' => now()->subYear(), 'approval_expires_on' => now()->addYears(3), 'approved_qualification_ids' => [$this->q->id]]);
        foreach (['Bheki Mabaso', 'Zodwa Khumalo'] as $m) {
            Mentor::query()->create(['employer_id' => $e->id, 'name' => $m, 'designation' => 'Foreman', 'years_experience' => 10, 'site_ids' => [$s->id]]);
        }

        return [$centre, $e, $s];
    });
});

function planCohort(object $test, array $overrides = []): string
{
    return $test->postJson(TYM_V1.'/cohorts', [
        'name' => 'Bricklayer February 2027', 'qualification_id' => $test->q->id, 'centre_id' => $test->centre->id, 'mode' => 'contact',
        'intake_on' => now()->addMonths(2)->toDateString(), 'planned_end_on' => now()->addMonths(14)->toDateString(), 'capacity' => 20, ...$overrides,
    ])->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');
}

function makeReady(object $test, string $id): void
{
    $test->putJson(TYM_V1."/cohorts/{$id}/staff", ['staff' => [
        ['user_id' => $test->people['Sipho']->id, 'role' => 'facilitator'], ['user_id' => $test->people['Sipho']->id, 'role' => 'assessor'],
        ['user_id' => $test->people['Lindiwe']->id, 'role' => 'moderator'],
    ]])->assertOk();
    $test->putJson(TYM_V1."/cohorts/{$id}/placements", ['placements' => [['site_id' => $test->site->id, 'planned_learners' => 20]]])->assertOk();
    signedAgreement($test);
}

function signedAgreement(object $test, string $status = 'completed'): void
{
    asTenant($test->tym, function () use ($test, $status): void {
        $t = Template::query()->create(['family_id' => (string) Str::ulid(), 'version' => 1, 'kind' => 'employer_mou', 'name' => 'MoU', 'body' => 'Text', 'parties' => ['employer', 'provider']]);
        Agreement::query()->create(['template_id' => $t->id, 'kind' => 'employer_mou', 'title' => 'MoU', 'body' => 'Text', 'body_sha256' => hash('sha256', 'Text'), 'merge' => [],
            'employer_id' => $test->employer->id, 'verify_code' => strtolower(Str::random(10))])->forceFill(['status' => $status])->save();
    });
}

function readiness(object $test, string $id): array
{
    return collect($test->getJson(TYM_V1."/cohorts/{$id}/readiness")->assertOk()->json('data.items'))->keyBy('key')->all();
}

it('opens a cohort only when nothing fails', function (): void {
    $id = planCohort($this);
    $items = readiness($this, $id);
    expect($items['qualification.enrolment']['status'])->toBe('pass')
        ->and($items['centre.scope']['status'])->toBe('pass')
        ->and($items['staff.facilitator']['status'])->toBe('fail')
        ->and($items['workplace.places']['status'])->toBe('fail');
    $this->postJson(TYM_V1."/cohorts/{$id}/open")->assertStatus(422)->assertJsonValidationErrors('readiness');

    makeReady($this, $id);
    $check = $this->getJson(TYM_V1."/cohorts/{$id}/readiness")->json('data');
    expect($check['ok'])->toBeTrue()->and($check['counts']['fail'])->toBe(0);
    $this->postJson(TYM_V1."/cohorts/{$id}/open")->assertOk()->assertJsonPath('data.status', 'open')->assertJsonPath('data.opened_with.ok', true);
});

it('fails on each kind of problem, with where to fix it', function (): void {
    $id = planCohort($this);
    makeReady($this, $id);

    // A registration that is waiting to be verified, a moderator who is not an assessor, an assessor registered elsewhere.
    $this->putJson(TYM_V1."/cohorts/{$id}/staff", ['staff' => [
        ['user_id' => $this->people['Thabo']->id, 'role' => 'facilitator'],
        ['user_id' => $this->people['Thabo']->id, 'role' => 'assessor'],
        ['user_id' => $this->people['Thabo']->id, 'role' => 'moderator'],
    ]])->assertOk();
    $items = readiness($this, $id);
    expect($items['staff.facilitator.'.$this->people['Thabo']->id]['status'])->toBe('pass')
        ->and($items['staff.assessor.'.$this->people['Thabo']->id])->toMatchArray(['status' => 'fail', 'fix' => 'credentials'])
        ->and($items['staff.moderator.'.$this->people['Thabo']->id]['status'])->toBe('fail');

    // The centre's scope and the qualification's dates.
    asTenant($this->tym, fn () => CentreQualification::query()->delete());
    asPlatform(fn () => $this->q->forceFill(['last_enrolment_on' => now()->addMonth()])->save());
    $items = readiness($this, $id);
    expect($items['centre.scope'])->toMatchArray(['status' => 'fail', 'fix' => 'centres'])
        ->and($items['qualification.enrolment']['status'])->toBe('fail');

    // Over capacity: two mentors × 10 = 20 places; plan 25.
    $this->putJson(TYM_V1."/cohorts/{$id}/placements", ['placements' => [['site_id' => $this->site->id, 'planned_learners' => 25]]])->assertOk();
    expect(readiness($this, $id)["workplace.site.{$this->site->id}"])->toMatchArray(['status' => 'fail', 'fix' => 'employers']);
});

it('treats an unsigned employer agreement as pending, and none at all as a failure', function (): void {
    $id = planCohort($this);
    $this->putJson(TYM_V1."/cohorts/{$id}/placements", ['placements' => [['site_id' => $this->site->id, 'planned_learners' => 20]]])->assertOk();
    expect(readiness($this, $id)["agreement.{$this->employer->id}"]['status'])->toBe('fail');
    signedAgreement($this, 'sent');
    expect(readiness($this, $id)["agreement.{$this->employer->id}"]['status'])->toBe('warn');
    signedAgreement($this);
    expect(readiness($this, $id)["agreement.{$this->employer->id}"]['status'])->toBe('pass');
});

it('warns when the only assessor also moderates', function (): void {
    $id = planCohort($this);
    makeReady($this, $id);
    $this->putJson(TYM_V1."/cohorts/{$id}/staff", ['staff' => [
        ['user_id' => $this->people['Sipho']->id, 'role' => 'facilitator'], ['user_id' => $this->people['Lindiwe']->id, 'role' => 'assessor'],
        ['user_id' => $this->people['Lindiwe']->id, 'role' => 'moderator'],
    ]])->assertOk();
    expect(readiness($this, $id)['staff.independence']['status'])->toBe('warn');
});

it('shares workplace places across open cohorts that overlap', function (): void {
    $first = planCohort($this);
    makeReady($this, $first);
    $this->postJson(TYM_V1."/cohorts/{$first}/open")->assertOk();

    $second = planCohort($this, ['name' => 'Bricklayer March 2027', 'capacity' => 5]);
    $options = $this->getJson(TYM_V1."/cohorts/{$second}/options")->assertOk()->json('data');
    expect($options['sites'][0])->toMatchArray(['free' => 0, 'approved' => true])
        ->and(collect($options['people']['assessor'])->firstWhere('name', 'Sipho Ndlovu')['ok'])->toBeTrue()
        ->and(collect($options['people']['assessor'])->firstWhere('name', 'Thabo Facilitator'))->toBeNull();
    $this->putJson(TYM_V1."/cohorts/{$second}/placements", ['placements' => [['site_id' => $this->site->id, 'planned_learners' => 5]]])->assertOk();
    expect(readiness($this, $second)["workplace.site.{$this->site->id}"]['status'])->toBe('fail');
});

it('refuses changes that would break an open cohort, and watches it daily', function (): void {
    $id = planCohort($this);
    makeReady($this, $id);
    $this->postJson(TYM_V1."/cohorts/{$id}/open")->assertOk();

    // Removing the moderator is refused and nothing changes.
    $this->putJson(TYM_V1."/cohorts/{$id}/staff", ['staff' => [['user_id' => $this->people['Sipho']->id, 'role' => 'facilitator'], ['user_id' => $this->people['Sipho']->id, 'role' => 'assessor']]])
        ->assertStatus(422)->assertJsonValidationErrors('readiness');
    expect($this->getJson(TYM_V1."/cohorts/{$id}")->json('data.staff'))->toHaveCount(3);
    $this->patchJson(TYM_V1."/cohorts/{$id}", ['centre_id' => $this->centre->id])->assertStatus(422);

    // The moderator's registration lapses mid-cohort: owners hear once.
    asTenant($this->tym, fn () => StaffCredential::query()->where('user_id', $this->people['Lindiwe']->id)->where('role', 'moderator')->update(['expires_on' => now()->subDay()]));
    Artisan::call('cohorts:watch');
    Notification::assertSentOnDemand(CohortAtRisk::class, fn (CohortAtRisk $n) => $n->cohort === 'Bricklayer February 2027' && str_contains($n->problems[0]['title'], 'Lindiwe'));
    $count = count(Notification::sentNotifications());
    Artisan::call('cohorts:watch');
    expect(count(Notification::sentNotifications()))->toBe($count);
    expect($this->getJson(TYM_V1.'/cohorts')->json('data.0.at_risk'))->toBeTrue();

    $this->postJson(TYM_V1."/cohorts/{$id}/close")->assertOk()->assertJsonPath('data.status', 'closed');
    $this->putJson(TYM_V1."/cohorts/{$id}/placements", ['placements' => []])->assertStatus(422);
});

it('keeps centre managers to their centres and cohorts to the provider', function (): void {
    $id = planCohort($this);
    $other = asTenant($this->tym, fn () => Centre::query()->create(['name' => 'Durban Campus']));
    $manager = makeMember($this->tym, [], ['email' => 'manager@tym.test', 'password' => 'correct horse battery staple']);
    asTenant($this->tym, fn () => DB::table('role_assignments')->insert([
        'id' => (string) Str::ulid(), 'tenant_id' => $this->tym->id, 'user_id' => $manager->id, 'role' => 'centre-manager', 'centre_id' => $other->id, 'created_at' => now(), 'updated_at' => now(),
    ]));
    signInAs($this, 'manager@tym.test');
    $this->getJson(TYM_V1.'/cohorts')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson(TYM_V1."/cohorts/{$id}")->assertNotFound();
    $this->postJson(TYM_V1.'/cohorts', ['name' => 'Sneaky', 'qualification_id' => $this->q->id, 'centre_id' => $this->centre->id, 'mode' => 'contact',
        'intake_on' => '2027-02-01', 'planned_end_on' => '2028-02-01', 'capacity' => 5])->assertStatus(422)->assertJsonValidationErrors('centre_id');

    expect(asTenant(makeProvider('other'), fn () => Cohort::query()->count()))->toBe(0);
    makeMember($this->tym, ['learner'], ['email' => 'learner@tym.test', 'password' => 'correct horse battery staple']);
    signInAs($this, 'learner@tym.test');
    $this->getJson(TYM_V1.'/cohorts')->assertForbidden();
});

it('checks readiness quickly', function (): void {
    $id = planCohort($this);
    makeReady($this, $id);
    $this->getJson(TYM_V1."/cohorts/{$id}/readiness")->assertOk(); // warm
    $start = hrtime(true);
    $this->getJson(TYM_V1."/cohorts/{$id}/readiness")->assertOk();
    expect((hrtime(true) - $start) / 1e6)->toBeLessThan(300);
});
