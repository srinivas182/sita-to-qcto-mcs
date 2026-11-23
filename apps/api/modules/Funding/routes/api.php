<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Funding\Http\Controllers\ClaimController;
use Modules\Funding\Http\Controllers\FundingController;
use Modules\Funding\Http\Controllers\StipendController;
use Modules\Identity\Http\Middleware\EnsureAccess;
use Modules\Identity\Http\Middleware\RequireMfaEnrolment;

Route::middleware(['auth:sanctum', EnsureAccess::class, RequireMfaEnrolment::class])->group(function (): void {
    Route::get('funders', [FundingController::class, 'funders']);
    Route::post('funders', [FundingController::class, 'saveFunder']);
    Route::put('funders/{funder}', [FundingController::class, 'saveFunder'])->whereUlid('funder');
    Route::get('cohorts/{cohort}/grant-agreements', [FundingController::class, 'cohort'])->whereUlid('cohort');
    Route::post('cohorts/{cohort}/grant-agreements', [FundingController::class, 'create'])->whereUlid('cohort');
    Route::get('cohorts/{cohort}/grant-candidates', [FundingController::class, 'candidates'])->whereUlid('cohort');
    Route::get('grant-agreements/{agreement}', [FundingController::class, 'show'])->whereUlid('agreement');
    Route::put('grant-agreements/{agreement}', [FundingController::class, 'update'])->whereUlid('agreement');
    Route::post('grant-tranches/{tranche}/evidence', [FundingController::class, 'evidence'])->whereUlid('tranche');

    Route::put('grant-agreements/{agreement}/stipend-rule', [StipendController::class, 'rule'])->whereUlid('agreement');
    Route::get('grant-agreements/{agreement}/stipend-runs', [StipendController::class, 'runs'])->whereUlid('agreement');
    Route::post('grant-agreements/{agreement}/stipend-runs', [StipendController::class, 'propose'])->whereUlid('agreement');
    Route::get('stipend-runs/{run}', [StipendController::class, 'show'])->whereUlid('run');
    Route::patch('stipend-lines/{line}', [StipendController::class, 'line'])->whereUlid('line');
    Route::post('stipend-runs/{run}/confirm', [StipendController::class, 'confirm'])->whereUlid('run');
    Route::get('stipend-runs/{run}/bank-file', [StipendController::class, 'bankFile'])->whereUlid('run')->middleware('throttle:20,60');
    Route::post('stipend-runs/{run}/paid', [StipendController::class, 'paid'])->whereUlid('run');
    Route::get('enrolments/{enrolment}/bank-account', [StipendController::class, 'bank'])->whereUlid('enrolment');
    Route::put('enrolments/{enrolment}/bank-account', [StipendController::class, 'saveBank'])->whereUlid('enrolment');
    Route::get('me/bank-account', [StipendController::class, 'myBank']);
    Route::put('me/bank-account', [StipendController::class, 'saveMyBank'])->middleware('throttle:10,60');
    Route::get('me/stipends', [StipendController::class, 'mine']);

    Route::get('grant-agreements/{agreement}/claims', [ClaimController::class, 'index'])->whereUlid('agreement');
    Route::post('grant-tranches/{tranche}/claims', [ClaimController::class, 'create'])->whereUlid('tranche');
    Route::get('grant-claims/{claim}', [ClaimController::class, 'show'])->whereUlid('claim');
    Route::get('grant-claims/{claim}/pack', [ClaimController::class, 'pack'])->whereUlid('claim')->middleware('throttle:20,60');
    Route::post('grant-claims/{claim}/sent', [ClaimController::class, 'sent'])->whereUlid('claim');
    Route::post('grant-claims/{claim}/queries', [ClaimController::class, 'query'])->whereUlid('claim');
    Route::post('claim-queries/{query}/resolved', [ClaimController::class, 'resolve'])->whereUlid('query');
    Route::post('grant-claims/{claim}/paid', [ClaimController::class, 'paid'])->whereUlid('claim');
    Route::post('grant-claims/{claim}/rejected', [ClaimController::class, 'rejected'])->whereUlid('claim');
    Route::post('grant-claims/{claim}/void', [ClaimController::class, 'void'])->whereUlid('claim');
});
