<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Files\Http\Controllers\FileController;
use Modules\Files\Http\Controllers\PublicImageController;
use Modules\Identity\Http\Middleware\EnsureAccess;
use Modules\Identity\Http\Middleware\RequireMfaEnrolment;

// Public, tenant-aware (resolved from the host).
Route::get('images/{file}/{variant}', PublicImageController::class)->whereUlid('file')->where('variant', '[a-z]{2,10}');

Route::middleware(['auth:sanctum', EnsureAccess::class, RequireMfaEnrolment::class])->group(function (): void {
    Route::post('files', [FileController::class, 'store'])->middleware('throttle:60,1');
    Route::get('files/{file}', [FileController::class, 'show'])->whereUlid('file');
    Route::put('files/{file}/content', [FileController::class, 'upload'])->whereUlid('file');
    Route::get('files/{file}/content', [FileController::class, 'content'])->whereUlid('file');
    Route::post('files/{file}/complete', [FileController::class, 'complete'])->whereUlid('file');
    Route::get('files/{file}/download', [FileController::class, 'download'])->whereUlid('file');
    Route::delete('files/{file}', [FileController::class, 'destroy'])->whereUlid('file');
});
