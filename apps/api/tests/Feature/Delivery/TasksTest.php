<?php

declare(strict_types=1);

require_once __DIR__.'/helpers.php';

use Illuminate\Support\Str;
use Modules\Enrolment\Models\Enrolment;
use Modules\Files\Models\File;

beforeEach(function (): void {
    deliverySetup($this);
    $this->intake = openIntake($this, ['document_types' => [], 'min_age' => null]);
    $this->ayanda = enrolLearner($this, '082 123 4567', '9202204720083');
    asPlatform(fn () => $this->ayanda->forceFill(['email' => 'ayanda@learner.test', 'password' => 'correct horse battery staple'])->save());
    $this->enrolment = asTenant($this->tym, fn () => Enrolment::query()->where('user_id', $this->ayanda->id)->value('id'));
    signInAs($this, 'sipho@tym.test'); // facilitators build tasks too
});

function quiz(): array
{
    return [
        ['type' => 'single', 'text' => 'Which mix is stronger?', 'options' => ['1:6', '1:3', '1:8'], 'correct' => [1], 'marks' => 2],
        ['type' => 'multi', 'text' => 'Which are PPE?', 'options' => ['Hard hat', 'Sandals', 'Gloves'], 'correct' => [0, 2], 'marks' => 2],
    ];
}

it('marks a multiple-choice quiz at once, keeps answers from learners, and limits attempts', function (): void {
    $this->postJson(TYM_V1.'/tasks', ['qualification_id' => $this->q->id, 'title' => 'Bad', 'questions' => [['type' => 'single', 'text' => 'Q?', 'options' => ['a', 'b'], 'correct' => [0, 1], 'marks' => 1]]])
        ->assertStatus(422)->assertJsonValidationErrors('questions.0.correct');
    $id = $this->postJson(TYM_V1.'/tasks', ['qualification_id' => $this->q->id, 'module_id' => $this->km->id, 'title' => 'Materials quiz', 'questions' => quiz(), 'attempts' => 2])
        ->assertCreated()->json('data.id');
    $this->postJson(TYM_V1."/tasks/{$id}/publish", ['published' => true])->assertOk();

    signInAs($this, 'ayanda@learner.test');
    $task = $this->getJson(TYM_V1."/me/courses/{$this->enrolment}/tasks")->assertOk()->json('data.0');
    expect($task['questions'][0])->not->toHaveKey('correct')->and($task['out_of'])->toBe(4);
    $this->postJson(TYM_V1."/me/tasks/{$id}/attempts", ['answers' => ['q1' => 1]])->assertStatus(422)->assertJsonValidationErrors('answers.q2');
    $first = $this->postJson(TYM_V1."/me/tasks/{$id}/attempts", ['answers' => ['q1' => 1, 'q2' => [0]]])->assertCreated()->json('data');
    expect($first)->toMatchArray(['status' => 'not_yet', 'score_pct' => 50, 'number' => 1]);
    $second = $this->postJson(TYM_V1."/me/tasks/{$id}/attempts", ['answers' => ['q1' => 1, 'q2' => [2, 0]]])->assertCreated()->json('data');
    expect($second)->toMatchArray(['status' => 'passed', 'score_pct' => 100, 'number' => 2]);
    $this->postJson(TYM_V1."/me/tasks/{$id}/attempts", ['answers' => ['q1' => 1, 'q2' => [0, 2]]])->assertStatus(422)->assertJsonValidationErrors('task');

    // Questions can't change once answered; a copy can.
    signInAs($this, 'sipho@tym.test');
    $this->patchJson(TYM_V1."/tasks/{$id}", ['questions' => quiz()])->assertStatus(422)->assertJsonValidationErrors('questions');
    $this->postJson(TYM_V1."/tasks/{$id}/copy")->assertCreated()->assertJsonPath('data.title', 'Materials quiz (copy)');
});

it('has the facilitator mark short answers and photo evidence with feedback', function (): void {
    $id = $this->postJson(TYM_V1.'/tasks', ['qualification_id' => $this->q->id, 'module_id' => $this->pm->id, 'title' => 'Lay a course', 'evidence' => true,
        'evidence_hint' => 'Photos of your course from the front and the side', 'pass_pct' => 60,
        'questions' => [...quiz(), ['type' => 'short', 'text' => 'Why wet the bricks first?', 'marks' => 4, 'guide' => 'So they do not suck the water from the mortar']]])->assertCreated()->json('data.id');
    $this->postJson(TYM_V1."/tasks/{$id}/publish", ['published' => true])->assertOk();

    signInAs($this, 'ayanda@learner.test');
    $this->postJson(TYM_V1."/me/tasks/{$id}/attempts", ['answers' => ['q1' => 1, 'q2' => [0, 2], 'q3' => 'So the mortar bonds']])->assertStatus(422)->assertJsonValidationErrors('file_ids');
    $photo = asTenant($this->tym, fn () => File::query()->create(['owner_id' => $this->ayanda->id, 'purpose' => 'task_evidence', 'original_name' => 'course.jpg', 'mime' => 'image/jpeg',
        'size' => 10, 'store_driver' => 'local', 'path' => 'x/'.Str::ulid().'.jpg', 'status' => 'ready'])->id);
    $a = $this->postJson(TYM_V1."/me/tasks/{$id}/attempts", ['answers' => ['q1' => 1, 'q2' => [0, 2], 'q3' => 'So the mortar bonds'], 'file_ids' => [$photo],
        'submitted_at' => now()->subHour()->toIso8601String()])->assertCreated()->json('data');
    expect($a)->toMatchArray(['status' => 'submitted', 'offline' => true]);
    $this->postJson(TYM_V1."/me/tasks/{$id}/attempts", ['answers' => ['q1' => 1, 'q2' => [0, 2], 'q3' => 'x'], 'file_ids' => [$photo]])->assertStatus(422)->assertJsonValidationErrors('task');

    signInAs($this, 'thabo@tym.test'); // also a facilitator on the cohort
    $queue = $this->getJson(TYM_V1.'/task-attempts')->assertOk()->json('data');
    expect($queue)->toHaveCount(1)->and($queue[0])->toMatchArray(['learner' => 'Ayanda Zulu', 'task' => 'Lay a course']);
    $detail = $this->getJson(TYM_V1."/task-attempts/{$a['id']}")->assertOk()->json('data');
    expect($detail['task']['questions'][2]['guide'])->toContain('suck the water')->and($detail['files'][0]['original_name'])->toBe('course.jpg');
    $this->postJson(TYM_V1."/task-attempts/{$a['id']}/mark", ['marks' => ['q3' => 9, 'evidence' => 8], 'feedback' => 'Good'])->assertStatus(422)->assertJsonValidationErrors('marks.q3');
    $marked = $this->postJson(TYM_V1."/task-attempts/{$a['id']}/mark", ['marks' => ['q3' => 2, 'evidence' => 6], 'feedback' => 'Straight course; check the perpends.'])->assertOk()->json('data');
    expect($marked)->toMatchArray(['status' => 'passed', 'marks' => 12, 'out_of' => 18, 'score_pct' => 67]);

    signInOwner($this, $this->tym, 'tasks@tym.test');
    $r = $this->getJson(TYM_V1."/cohorts/{$this->cohort->id}/task-results")->assertOk()->json('data');
    expect($r['tasks'][0]['module'])->toBe('PM-01')->and($r['learners'][0]['passed'])->toBe(1)
        ->and($r['learners'][0]['results'][$id])->toMatchArray(['status' => 'passed', 'score_pct' => 67, 'attempts' => 1]);
});

it('keeps tasks from learners who are not enrolled, and marking from other facilitators', function (): void {
    $id = $this->postJson(TYM_V1.'/tasks', ['qualification_id' => $this->q->id, 'title' => 'Quiz', 'questions' => quiz()])->json('data.id');
    $this->postJson(TYM_V1."/tasks/{$id}/publish", ['published' => true])->assertOk();
    $outsider = makeMember($this->tym, ['facilitator'], ['email' => 'out@tym.test', 'password' => 'correct horse battery staple']);
    signInAs($this, 'out@tym.test');
    $this->postJson(TYM_V1."/me/tasks/{$id}/attempts", ['answers' => ['q1' => 1, 'q2' => [0, 2]]])->assertNotFound();
    expect($this->getJson(TYM_V1.'/task-attempts')->assertOk()->json('data'))->toBe([]);
});
