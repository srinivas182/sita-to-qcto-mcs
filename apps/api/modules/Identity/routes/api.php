<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Identity\Http\Controllers\ConsentController;
use Modules\Identity\Http\Controllers\MfaController;
use Modules\Identity\Http\Controllers\OtpController;
use Modules\Identity\Http\Controllers\PasskeyController;
use Modules\Identity\Http\Controllers\PasswordController;
use Modules\Identity\Http\Controllers\SessionController;
use Modules\Identity\Http\Middleware\EnsureAccess;

// Sign-in (public). Each method also has its own finer limits in its service.
Route::middleware('throttle:auth')->prefix('auth')->group(function (): void {
    Route::post('otp/request', [OtpController::class, 'request']);
    Route::post('otp/verify', [OtpController::class, 'verify']);
    Route::post('login', [PasswordController::class, 'login']);
    Route::post('password/forgot', [PasswordController::class, 'forgot']);
    Route::post('password/reset', [PasswordController::class, 'reset']);
    Route::post('mfa/totp/challenge', [PasswordController::class, 'mfaChallenge']);
    Route::post('passkeys/login/options', [PasskeyController::class, 'loginOptions']);
    Route::post('passkeys/login', [PasskeyController::class, 'login']);
    Route::post('token/refresh', [SessionController::class, 'refresh']);
});

// Signed in, any security state (so MFA set-up is always reachable).
Route::middleware(['auth:sanctum', EnsureAccess::class])->group(function (): void {
    Route::get('me', [SessionController::class, 'me']);
    Route::post('auth/logout', [SessionController::class, 'logout']);

    Route::post('auth/mfa/totp/enable', [MfaController::class, 'enable']);
    Route::post('auth/mfa/totp/confirm', [MfaController::class, 'confirm']);
    Route::post('auth/mfa/recovery-codes', [MfaController::class, 'regenerate']);
    Route::delete('auth/mfa/totp', [MfaController::class, 'disable']);

    Route::get('auth/passkeys', [PasskeyController::class, 'index']);
    Route::post('auth/passkeys/options', [PasskeyController::class, 'options']);
    Route::post('auth/passkeys/register', [PasskeyController::class, 'register']);
    Route::delete('auth/passkeys/{passkey}', [PasskeyController::class, 'destroy']);

    Route::get('auth/devices', [SessionController::class, 'devices']);
    Route::patch('auth/devices/{device}', [SessionController::class, 'updateDevice']);
    Route::delete('auth/devices/{device}', [SessionController::class, 'revokeDevice']);

    Route::get('me/consents', [ConsentController::class, 'index']);
    Route::post('me/consents', [ConsentController::class, 'store']);
    Route::delete('me/consents/{purpose}', [ConsentController::class, 'withdraw']);
});
