<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Identity\Http\Middleware\EnsureAccess;
use Modules\Identity\Http\Middleware\RequireMfaEnrolment;
use Modules\Logbook\Http\Controllers\LogbookController;
use Modules\Logbook\Http\Controllers\MentorLogbookController;

Route::middleware(['auth:sanctum', EnsureAccess::class, RequireMfaEnrolment::class])->group(function (): void {
    Route::get('logbook-rules', [LogbookController::class, 'index']);
    Route::get('modules/{module}/logbook-rules', [LogbookController::class, 'rules'])->whereUlid('module');
    Route::put('modules/{module}/logbook-rules', [LogbookController::class, 'saveRules'])->whereUlid('module');
    Route::get('cohorts/{cohort}/learner-placements', [LogbookController::class, 'placements'])->whereUlid('cohort');
    Route::post('cohorts/{cohort}/learner-placements', [LogbookController::class, 'place'])->whereUlid('cohort');
    Route::post('learner-placements/{placement}/end', [LogbookController::class, 'endPlacement'])->whereUlid('placement');
    Route::get('cohorts/{cohort}/logbook', [LogbookController::class, 'summary'])->whereUlid('cohort');
    Route::get('cohorts/{cohort}/logbook/{user}', [LogbookController::class, 'learner'])->whereUlid('cohort')->whereUlid('user');
    Route::post('logbook-signoffs/{signoff}/check', [LogbookController::class, 'check'])->whereUlid('signoff');
    Route::get('enrolments/{enrolment}/work-experience', [LogbookController::class, 'statement'])->whereUlid('enrolment');
    Route::get('me/logbook', [LogbookController::class, 'mine']);
    Route::post('me/logbook', [LogbookController::class, 'record'])->middleware('throttle:120,60');
    Route::post('me/logbook/{entry}/correct', [LogbookController::class, 'correct'])->whereUlid('entry')->middleware('throttle:60,60');
});

// Workplace mentors (Host employer users) sign weeks; MFA follows their role's rule, as for /me/mentoring.
Route::middleware(['auth:sanctum', EnsureAccess::class])->group(function (): void {
    Route::get('mentoring/weeks', [MentorLogbookController::class, 'weeks']);
    Route::get('logbook-weeks/{week}', [MentorLogbookController::class, 'show'])->whereUlid('week');
    Route::post('logbook-weeks/{week}/sign', [MentorLogbookController::class, 'sign'])->whereUlid('week')->middleware('throttle:60,60');
    Route::post('logbook-signoffs', [MentorLogbookController::class, 'final'])->middleware('throttle:30,60');
    Route::post('logbook-weeks/{week}/return', [MentorLogbookController::class, 'return'])->whereUlid('week')->middleware('throttle:60,60');
});
