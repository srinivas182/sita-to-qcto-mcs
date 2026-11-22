<?php

declare(strict_types=1);

namespace Modules\Identity\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Identity\Models\Consent;
use Modules\Identity\Models\User;

/**
 * POPIA consents per provider. Each grant records the notice version; withdrawing
 * keeps the record and stamps withdrawn_at.
 */
final class ConsentController
{
    public const PURPOSES = ['terms', 'privacy', 'data_sharing_seta', 'job_matching', 'marketing'];

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => Consent::query()->where('user_id', $user->id)->orderByDesc('granted_at')->get()->map(fn (Consent $c): array => [
            'purpose' => $c->purpose,
            'version' => $c->version,
            'granted_at' => $c->granted_at->toIso8601String(),
            'withdrawn_at' => $c->withdrawn_at?->toIso8601String(),
        ])]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'purpose' => ['required', 'in:'.implode(',', self::PURPOSES)],
            'version' => ['required', 'string', 'max:20'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $current = Consent::query()->where('user_id', $user->id)->where('purpose', $data['purpose'])
            ->where('version', $data['version'])->whereNull('withdrawn_at')->first();

        $consent = $current ?? Consent::query()->create([
            'user_id' => $user->id,
            'purpose' => $data['purpose'],
            'version' => $data['version'],
            'granted_at' => now(),
            'ip' => $request->ip(),
        ]);

        return response()->json(['purpose' => $consent->purpose, 'version' => $consent->version], $current ? 200 : 201);
    }

    public function withdraw(Request $request, string $purpose): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $count = Consent::query()->where('user_id', $user->id)->where('purpose', $purpose)->whereNull('withdrawn_at')
            ->get()->each(fn (Consent $c) => $c->forceFill(['withdrawn_at' => now()])->save())->count();

        return response()->json(['withdrawn' => $count]);
    }
}
