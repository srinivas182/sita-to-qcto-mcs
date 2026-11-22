<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'name' => 'Skills Ecosystem API',
    'docs' => '/v1/docs',
]));
