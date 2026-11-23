<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Enrolment\Http\Controllers\ApplicantController;
use Modules\Enrolment\Http\Controllers\ApplicationController;
use Modules\Enrolment\Http\Controllers\IntakeController;
use Modules\Enrolment\Http\Controllers\MyApplicationsController;
use Modules\Enrolment\Http\Controllers\MyDocumentsController;
use Modules\Enrolment\Http\Controllers\MyOffersController;
use Modules\Enrolment\Http\Controllers\OfferController;
use Modules\Enrolment\Http\Controllers\PublicIntakeController;
use Modules\Enrolment\Http\Controllers\PublicOfferController;
use Modules\Enrolment\Http\Controllers\QctoController;
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
    Route::post('learner-applications/{application}/offer', [OfferController::class, 'store'])->whereUlid('application');
    Route::post('offers/{offer}/withdraw', [OfferController::class, 'withdraw'])->whereUlid('offer');
    Route::get('enrolments', [OfferController::class, 'enrolments']);
    Route::get('cohorts/{cohort}/qcto-enrolment', [QctoController::class, 'show'])->whereUlid('cohort');
    Route::get('cohorts/{cohort}/qcto-enrolment/workbook', [QctoController::class, 'workbook'])->whereUlid('cohort')->middleware('throttle:30,60');
    Route::post('cohorts/{cohort}/qcto-enrolment/sent', [QctoController::class, 'sent'])->whereUlid('cohort');
    Route::post('learner-applications/{application}/identity-check', [ApplicantController::class, 'identityCheck'])->whereUlid('application')->middleware('throttle:30,60');
    Route::post('enrolments/{enrolment}/end', [OfferController::class, 'end'])->whereUlid('enrolment');
    Route::get('consent-text', [OfferController::class, 'consentText']);
    Route::put('consent-text', [OfferController::class, 'updateConsentText']);
    Route::get('enrolment-settings', [ApplicantController::class, 'settings']);
    Route::patch('enrolment-settings', [ApplicantController::class, 'updateSettings']);

    Route::get('me/documents', [MyDocumentsController::class, 'index']);
    Route::post('me/documents', [MyDocumentsController::class, 'store'])->middleware('throttle:30,60');
    Route::delete('me/documents/{document}', [MyDocumentsController::class, 'destroy'])->whereUlid('document');
    Route::get('me/offers', [MyOffersController::class, 'index']);
    Route::post('me/offers/{offer}/accept', [MyOffersController::class, 'accept'])->whereUlid('offer')->middleware('throttle:10,60');
    Route::post('me/offers/{offer}/decline', [MyOffersController::class, 'decline'])->whereUlid('offer');
    Route::get('me/courses', [MyOffersController::class, 'courses']);
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

// The offer link: see, accept with consent, or decline.
Route::prefix('public/offers/{token}')->where(['token' => '[A-Za-z0-9]{48}'])->middleware('throttle:30,1')->group(function (): void {
    Route::get('/', [PublicOfferController::class, 'show']);
    Route::post('accept', [PublicOfferController::class, 'accept'])->middleware('throttle:10,60');
    Route::post('decline', [PublicOfferController::class, 'decline'])->middleware('throttle:10,60');
});
