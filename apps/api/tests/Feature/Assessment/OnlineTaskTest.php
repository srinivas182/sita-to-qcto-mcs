<?php

declare(strict_types=1);

require_once __DIR__.'/helpers.php';

use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();
    assessmentSetup($this);
});

it('links a summative test to an online task and shows the assessor the learner\'s attempt', function (): void {
    $quiz = [['type' => 'single', 'text' => 'Which mix is stronger?', 'options' => ['1:6', '1:3'], 'correct' => [1], 'marks' => 2]];
    $task = $this->postJson(TYM_V1.'/tasks', ['qualification_id' => $this->q->id, 'module_id' => $this->km->id, 'title' => 'Materials test (online)', 'questions' => $quiz, 'attempts' => 2])
        ->assertCreated()->json('data.id');
    $save = fn (?string $taskId, string $method = 'test') => $this->putJson(TYM_V1."/modules/{$this->km->id}/instruments", ['instruments' => [
        ['id' => $this->kmTest['id'], 'method' => $method, 'title' => 'Materials test', 'pass_mark' => $method === 'test' ? 60 : null, 'task_id' => $taskId,
            'criteria' => [['key' => 'k1', 'text' => 'Identify bricks, blocks and mortar types']]],
    ]]);
    $save($task)->assertStatus(422)->assertJsonValidationErrors('instruments.0.task_id'); // not published yet
    $this->postJson(TYM_V1."/tasks/{$task}/publish", ['published' => true])->assertOk();
    $save($task)->assertOk();
    expect($this->getJson(TYM_V1."/modules/{$this->km->id}/instruments")->json('data'))
        ->toHaveKey('tasks.0.title', 'Materials test (online)')->toHaveKey('instruments.0.task_id', $task);

    signInAs($this, 'ayanda@learner.test');
    $this->postJson(TYM_V1."/me/tasks/{$task}/attempts", ['answers' => ['q1' => 1]])->assertCreated();

    signInAs($this, 'naledi@tym.test');
    $online = $this->getJson(TYM_V1."/cohorts/{$this->cohort->id}/results/{$this->enrolment}")->assertOk()->json("data.online.{$this->kmTest['id']}");
    expect($online)->toMatchArray(['task_title' => 'Materials test (online)', 'attempts' => 1, 'pass_pct' => 70])
        ->and($online['latest'])->toMatchArray(['number' => 1, 'score_pct' => 100, 'status' => 'passed']);
});
