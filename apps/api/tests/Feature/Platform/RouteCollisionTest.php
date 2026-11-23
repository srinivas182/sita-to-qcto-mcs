<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
 * Two modules registering the same method and path shape (e.g. GET /v1/applications/{id})
 * means the later one silently takes the other's requests. Parameter names don't matter.
 */
it('has no two routes on the same method and path shape', function (): void {
    $seen = [];
    $clashes = [];
    foreach (Route::getRoutes()->getRoutes() as $route) {
        $shape = preg_replace('/\{[^}]+\}/', '{}', $route->uri());
        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $key = $method.' '.$shape.' '.($route->getDomain() ?? '');
            if (isset($seen[$key])) {
                $clashes[] = "{$key}: {$seen[$key]} and {$route->getActionName()}";
            }
            $seen[$key] = $route->getActionName();
        }
    }
    expect($clashes)->toBe([]);
});
