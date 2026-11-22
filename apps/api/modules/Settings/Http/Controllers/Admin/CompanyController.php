<?php

declare(strict_types=1);

namespace Modules\Settings\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Identity\Models\User;
use Modules\Settings\Services\CompanySettings;

/** Settings → Company. Super Admin, platform context. */
final class CompanyController
{
    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->company()->get(), 'complete' => $this->company()->isComplete()]);
    }

    public function update(Request $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $data = $this->company()->update($request->all(), $actor);

        return response()->json(['data' => $data, 'complete' => $this->company()->isComplete()]);
    }

    private function company(): CompanySettings
    {
        return app(CompanySettings::class);
    }
}
