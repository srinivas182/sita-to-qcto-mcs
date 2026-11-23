<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Enrolment\Http\Controllers\ApplicantController;
use Modules\Enrolment\Http\Controllers\ApplicationController;
use Modules\Enrolment\Http\Controllers\IntakeController;
use Modules\Enrolment\Http\Controllers\MyApplicationsController;
use Modules\Enrolment\Http\Controllers\MyDocumentsController;
use Modules\Enrolment\Http\Controllers\PublicIntakeController;
use Modules\Identity\Http\Middleware\EnsureAccess;
use Modules\Identity\Http\Middleware\RequireMfaEnrolment;

Route::middleware(['auth:sanctum', EnsureAccess::class, RequireMfaEnrolment::class])->group(function (): void {
    Route::get('intakes', [IntakeController::class, 'index']);
    Route::post('intakes', [IntakeController::class, 'store']);
    Route::get('intakes/{intake}', [IntakeController::class, 'show'])->whereUlid('intake');
    Route::patch('intakes/{intake}', [IntakeController::class, 'update'])->whereUlid('intake');
    Route::post('intakes/{intake}/publish', [IntakeController::class, 'publish'])->whereUlid('intake');
    Route::post('intakes/{intake}/close', [IntakeController::class, 'close'])->whereUlid('intake');
    Route::get('learner-applications', [ApplicationController::class, 'index']);
    Route::post('learner-applications/{application}/{action}', [ApplicationController::class, 'decide'])->whereUlid('application')->whereIn('action', ['shortlist', 'waitlist', 'decline', 'reopen']);

    Route::get('learner-applications/{application}', [ApplicantController::class, 'show'])->whereUlid('application');
    Route::post('learner-applications/{application}/request-documents', [ApplicantController::class, 'requestDocuments'])->whereUlid('application')->middleware('throttle:20,60');
    Route::post('learner-documents/{document}/review', [ApplicantController::class, 'review'])->whereUlid('document');
    Route::get('enrolment-settings', [ApplicantController::class, 'settings']);
    Route::patch('enrolment-settings', [ApplicantController::class, 'updateSettings']);

    Route::get('me/documents', [MyDocumentsController::class, 'index']);
    Route::post('me/documents', [MyDocumentsController::class, 'store'])->middleware('throttle:30,60');
    Route::delete('me/documents/{document}', [MyDocumentsController::class, 'destroy'])->whereUlid('document');
    Route::get('me/applications', [MyApplicationsController::class, 'index']);
    Route::post('me/applications', [MyApplicationsController::class, 'store'])->middleware('throttle:10,60');
    Route::post('me/applications/{application}/withdraw', [MyApplicationsController::class, 'withdraw'])->whereUlid('application');
});

// Public, on the provider's address: open intakes, one intake, and applying with a phone PIN.
Route::prefix('public/intakes')->middleware('throttle:60,1')->group(function (): void {
    Route::get('/', [PublicIntakeController::class, 'index']);
    Route::get('{slug}', [PublicIntakeController::class, 'show'])->where('slug', '[a-zA-Z0-9]{8}');
    Route::post('{slug}/code', [PublicIntakeController::class, 'code'])->where('slug', '[a-zA-Z0-9]{8}')->middleware('throttle:30,60'); // per phone limits are in the service
    Route::post('{slug}/apply', [PublicIntakeController::class, 'apply'])->where('slug', '[a-zA-Z0-9]{8}')->middleware('throttle:10,60');
});
