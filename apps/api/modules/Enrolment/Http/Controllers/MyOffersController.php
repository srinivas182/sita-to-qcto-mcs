<?php

declare(strict_types=1);

namespace Modules\Enrolment\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Enrolment\Models\Application;
use Modules\Enrolment\Models\Offer;
use Modules\Enrolment\Services\EnrolmentService;
use Modules\Enrolment\Services\OfferService;
use Modules\Identity\Models\User;

/** The signed-in learner's offers and courses (web and app). Only enrolled courses are listed. */
final class MyOffersController
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->mine($request)->latest()->get()->map(fn (Offer $o) => $this->offers()->present($o))->values()->all()]);
    }

    public function accept(Request $request, string $offer): JsonResponse
    {
        $channel = $request->header('X-Client') === 'mobile' ? 'app' : 'web';
        $o = $this->offers()->accept($this->mine($request)->whereKey($offer)->firstOrFail(), $request->all(), $channel, $request->ip());

        return response()->json(['data' => $this->offers()->present($o)]);
    }

    public function decline(Request $request, string $offer): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $o = $this->mine($request)->whereKey($offer)->firstOrFail();
        $this->offers()->decline($o, $data['reason'] ?? null);

        return response()->json(['data' => $this->offers()->present($o->refresh())]);
    }

    public function courses(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => EnrolmentService::courses($user->id)]);
    }

    /** @return Builder<Offer> */
    private function mine(Request $request): Builder
    {
        /** @var User $user */
        $user = $request->user();

        return Offer::query()->whereIn('application_id', Application::query()->where('user_id', $user->id)->select('id'));
    }

    /** Resolved per request: controllers are reused, the tenant context is not. */
    private function offers(): OfferService
    {
        return app(OfferService::class);
    }
}
