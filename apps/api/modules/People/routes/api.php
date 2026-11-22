<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Identity\Http\Middleware\EnsureAccess;
use Modules\Identity\Http\Middleware\RequireMfaEnrolment;
use Modules\People\Http\Controllers\PeopleController;

Route::middleware(['auth:sanctum', EnsureAccess::class, RequireMfaEnrolment::class])->group(function (): void {
    Route::get('me/profile', [PeopleController::class, 'myProfile']);
    Route::patch('me/profile', [PeopleController::class, 'updateMyProfile']);
    Route::get('me/credentials', [PeopleController::class, 'myCredentials']);
    Route::post('me/credentials', [PeopleController::class, 'addMyCredential']);

    Route::get('people/credentials', [PeopleController::class, 'register']);
    Route::post('people/import', [PeopleController::class, 'import'])->middleware('throttle:10,60');
    Route::get('people/{person}/profile', [PeopleController::class, 'profile'])->whereUlid('person');
    Route::patch('people/{person}/profile', [PeopleController::class, 'updateProfile'])->whereUlid('person');
    Route::get('people/{person}/credentials', [PeopleController::class, 'credentials'])->whereUlid('person');
    Route::post('people/{person}/credentials', [PeopleController::class, 'addCredential'])->whereUlid('person');
    Route::get('people/{person}/eligibility', [PeopleController::class, 'eligibility'])->whereUlid('person');
    Route::patch('credentials/{credential}', [PeopleController::class, 'updateCredential'])->whereUlid('credential');
    Route::post('credentials/{credential}/verify', [PeopleController::class, 'verifyCredential'])->whereUlid('credential');
    Route::post('credentials/{credential}/reject', [PeopleController::class, 'rejectCredential'])->whereUlid('credential');
});
