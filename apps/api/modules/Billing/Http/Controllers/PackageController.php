<?php

declare(strict_types=1);

namespace Modules\Billing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Billing\Models\Package;

/** Public price list (pricing page, sign-up). */
final class PackageController
{
    public function __invoke(): JsonResponse
    {
        $packages = Package::query()->where('active', true)->where('public', true)->orderBy('sort')->get();

        return response()->json([
            'data' => $packages->map(fn (Package $p) => $p->present())->all(),
            'vat_inclusive' => false,
            'example_pricing' => $packages->contains(fn (Package $p) => $p->is_example),
        ])->header('Cache-Control', 'public, max-age=60, stale-while-revalidate=600');
    }
}
