<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Funding\Http\Controllers\FundingController;
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
});
