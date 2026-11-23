<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Assessment\Http\Controllers\AssessmentController;
use Modules\Assessment\Http\Controllers\ModerationController;
use Modules\Assessment\Http\Controllers\StatementController;
use Modules\Identity\Http\Middleware\EnsureAccess;
use Modules\Identity\Http\Middleware\RequireMfaEnrolment;

Route::middleware(['auth:sanctum', EnsureAccess::class, RequireMfaEnrolment::class])->group(function (): void {
    Route::get('assessment-plan', [AssessmentController::class, 'plan']);
    Route::get('modules/{module}/instruments', [AssessmentController::class, 'instruments'])->whereUlid('module');
    Route::put('modules/{module}/instruments', [AssessmentController::class, 'saveInstruments'])->whereUlid('module');
    Route::get('assessment-settings', [AssessmentController::class, 'settings']);
    Route::put('assessment-settings', [AssessmentController::class, 'saveSettings']);
    Route::get('cohorts/{cohort}/results', [AssessmentController::class, 'cohort'])->whereUlid('cohort');
    Route::get('cohorts/{cohort}/results/{enrolment}', [AssessmentController::class, 'learner'])->whereUlid('cohort')->whereUlid('enrolment');
    Route::post('assessment-results', [AssessmentController::class, 'decide'])->middleware('throttle:240,60');
    Route::get('me/results', [AssessmentController::class, 'mine']);
    Route::get('me/assessing', [AssessmentController::class, 'assessing']);
    Route::get('me/moderation', [ModerationController::class, 'queue']);
    Route::get('moderation-samples/{sample}', [ModerationController::class, 'sample'])->whereUlid('sample');
    Route::post('moderation-samples/{sample}/decide', [ModerationController::class, 'decide'])->whereUlid('sample');
    Route::get('cohorts/{cohort}/moderation', [ModerationController::class, 'cohort'])->whereUlid('cohort');
    Route::post('cohorts/{cohort}/moderation-reports', [ModerationController::class, 'report'])->whereUlid('cohort');
    Route::get('moderation-reports/{report}/pdf', [ModerationController::class, 'pdf'])->whereUlid('report');
    Route::post('assessment-results/{result}/appeal', [ModerationController::class, 'appeal'])->whereUlid('result')->middleware('throttle:20,60');
    Route::get('cohorts/{cohort}/statement-readiness', [StatementController::class, 'readiness'])->whereUlid('cohort');
    Route::post('enrolments/{enrolment}/statement-of-results', [StatementController::class, 'issue'])->whereUlid('enrolment');
    Route::post('statements-of-results/{statement}/withdraw', [StatementController::class, 'withdraw'])->whereUlid('statement');
    Route::get('statements-of-results/{statement}/pdf', [StatementController::class, 'pdf'])->whereUlid('statement');
    Route::get('me/statements-of-results', [StatementController::class, 'mine']);
    Route::post('appeals/{appeal}/decide', [ModerationController::class, 'decideAppeal'])->whereUlid('appeal');
});

// Public: anyone holding a Statement of Results can check it is genuine.
Route::get('public/sor/{code}', [StatementController::class, 'verify'])->where('code', '[A-Za-z0-9]{10}')->middleware('throttle:30,1');
