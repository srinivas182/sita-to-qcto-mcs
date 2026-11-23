<?php

declare(strict_types=1);

require_once __DIR__.'/helpers.php';

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Modules\Delivery\Models\Material;
use Modules\Enrolment\Models\Enrolment;
use Modules\Files\Models\File;
use Modules\Files\Storage\StoreFactory;
use Modules\Identity\Models\User;

beforeEach(function (): void {
    deliverySetup($this);
    $this->intake = openIntake($this, ['document_types' => [], 'min_age' => null]);
    $this->ayanda = enrolLearner($this, '082 123 4567', '9202204720083');
    asPlatform(fn () => $this->ayanda->forceFill(['email' => 'ayanda@learner.test', 'password' => 'correct horse battery staple'])->save());
    $this->enrolment = asTenant($this->tym, fn () => Enrolment::query()->where('user_id', $this->ayanda->id)->value('id'));
    signInOwner($this, $this->tym, 'mat@tym.test');
});

function materialFile(object $test, string $bytes, string $mime, string $name): string
{
    $path = 'test/'.Str::ulid().'-'.$name;
    app(StoreFactory::class)->current()[0]->disk()->put($path, $bytes);

    return asTenant($test->tym, fn () => File::query()->create(['owner_id' => $test->sipho->id, 'purpose' => 'learning_material', 'original_name' => $name, 'mime' => $mime,
        'size' => strlen($bytes), 'store_driver' => 'local', 'path' => $path, 'status' => 'ready'])->id);
}

it('lets staff add, check and publish materials; learners see only their enrolled course, published', function (): void {
    $pdf = materialFile($this, '%PDF-1.4 notes', 'application/pdf', 'notes.pdf');
    $this->postJson(TYM_V1.'/materials', ['qualification_id' => $this->q->id, 'title' => 'Notes', 'kind' => 'video', 'file_id' => $pdf])->assertStatus(422)->assertJsonValidationErrors('file_id');
    $this->postJson(TYM_V1.'/materials', ['qualification_id' => $this->q->id, 'title' => 'Notes', 'kind' => 'document', 'module_id' => $this->wm->id])->assertStatus(422)->assertJsonValidationErrors('file_id');
    $doc = $this->postJson(TYM_V1.'/materials', ['qualification_id' => $this->q->id, 'module_id' => $this->km->id, 'title' => 'Building materials notes', 'kind' => 'document', 'file_id' => $pdf])->assertCreated()->json('data.id');
    $link = $this->postJson(TYM_V1.'/materials', ['qualification_id' => $this->q->id, 'title' => 'SANS 10400 summary', 'kind' => 'link', 'url' => 'https://example.org/sans'])->assertCreated()->json('data.id');
    $this->postJson(TYM_V1.'/materials', ['qualification_id' => $this->q->id, 'title' => 'Bad', 'kind' => 'link', 'url' => 'http://insecure.test'])->assertStatus(422)->assertJsonValidationErrors('url');
    $other = catalogueEntry('Occupational Certificate: Plumber', '91779');
    $hidden = $this->postJson(TYM_V1.'/materials', ['qualification_id' => $other->id, 'title' => 'Pipes', 'kind' => 'document', 'file_id' => materialFile($this, '%PDF-1.4 x', 'application/pdf', 'p.pdf')])->assertCreated()->json('data.id');
    foreach ([$doc, $hidden] as $id) {
        $this->postJson(TYM_V1."/materials/{$id}/publish", ['published' => true])->assertOk();
    }

    signInAs($this, 'ayanda@learner.test');
    $course = $this->getJson(TYM_V1."/me/courses/{$this->enrolment}/materials")->assertOk()->json('data');
    expect(collect($course['materials'])->pluck('title')->all())->toBe(['Building materials notes']) // the draft link and the other course are not shown
        ->and(collect($course['modules'])->pluck('code')->all())->toContain('KM-01');
    $dl = $this->getJson(TYM_V1."/me/materials/{$doc}/download")->assertOk()->json('data');
    expect($dl['via'])->toBe('api')->and($dl['url'])->toContain("/v1/me/materials/{$doc}/content");
    expect($this->get(TYM_V1."/me/materials/{$doc}/content")->assertOk()->streamedContent())->toBe('%PDF-1.4 notes');
    $this->getJson(TYM_V1."/me/materials/{$hidden}/download")->assertNotFound();
    $this->getJson(TYM_V1."/me/materials/{$link}/download")->assertNotFound();
    $this->postJson(TYM_V1."/me/materials/{$doc}/progress", ['completed' => true])->assertOk()->assertJsonPath('data.completed', true);
    expect($this->getJson(TYM_V1."/me/courses/{$this->enrolment}/materials")->json('data.materials.0'))->toMatchArray(['opened' => true, 'completed' => true, 'version' => 1]);

    // A new file makes a new version, so the phone fetches it again.
    signInOwner($this, $this->tym, 'mat2@tym.test');
    $this->patchJson(TYM_V1."/materials/{$doc}", ['file_id' => materialFile($this, '%PDF-1.4 notes v2', 'application/pdf', 'notes.pdf')])->assertOk()->assertJsonPath('data.version', 2);
    signInAs($this, 'ayanda@learner.test');
    $pack = $this->getJson(TYM_V1."/me/courses/{$this->enrolment}/offline-pack")->assertOk()->json('data');
    expect($pack['items'][0])->toMatchArray(['id' => $doc, 'version' => 2, 'size' => 17])->and($pack['total_bytes'])->toBe(17);
});

it('refuses materials to someone who is not enrolled', function (): void {
    $doc = $this->postJson(TYM_V1.'/materials', ['qualification_id' => $this->q->id, 'title' => 'Notes', 'kind' => 'document', 'file_id' => materialFile($this, '%PDF-1.4 a', 'application/pdf', 'a.pdf')])->json('data.id');
    $this->postJson(TYM_V1."/materials/{$doc}/publish", ['published' => true])->assertOk();
    $applicant = applyAs($this, '082 444 5555', '9505055800089', 'Nomsa');
    $nomsa = asPlatform(fn () => User::query()->findOrFail($applicant->user_id));
    asPlatform(fn () => $nomsa->forceFill(['email' => 'nomsa@learner.test', 'password' => 'correct horse battery staple'])->save());
    signInAs($this, 'nomsa@learner.test');
    $this->getJson(TYM_V1."/me/materials/{$doc}/download")->assertNotFound();
    $this->getJson(TYM_V1."/me/courses/{$this->enrolment}/materials")->assertNotFound(); // someone else's enrolment
});

it('makes a low-data copy of a video', function (): void {
    if (! Process::run(['ffmpeg', '-version'])->successful()) {
        $this->markTestSkipped('ffmpeg is not installed here.');
    }
    $tmp = tempnam(sys_get_temp_dir(), 'v').'.mp4';
    Process::run(['ffmpeg', '-y', '-f', 'lavfi', '-i', 'testsrc=size=1280x720:rate=25:duration=2', '-f', 'lavfi', '-i', 'sine=duration=2', '-c:v', 'libx264', '-crf', '18', '-c:a', 'aac', '-shortest', $tmp])->throw();
    $bytes = (string) file_get_contents($tmp);
    @unlink($tmp);
    $id = $this->postJson(TYM_V1.'/materials', ['qualification_id' => $this->q->id, 'title' => 'Laying a course', 'kind' => 'video', 'file_id' => materialFile($this, $bytes, 'video/mp4', 'course.mp4')])
        ->assertCreated()->json('data.id');
    $m = asTenant($this->tym, fn () => Material::query()->findOrFail($id));
    $low = asTenant($this->tym, fn () => File::query()->findOrFail($m->low_file_id));
    expect($m->low_status)->toBe('ready')->and($m->duration_s)->toBe(2)->and($low->size)->toBeLessThan(strlen($bytes))->and($low->mime)->toBe('video/mp4');
});
