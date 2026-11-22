<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Platform\Http\HealthController;

Route::get('/health', [HealthController::class, 'live'])->name('health.live');
Route::get('/ready', [HealthController::class, 'ready'])->name('health.ready');
