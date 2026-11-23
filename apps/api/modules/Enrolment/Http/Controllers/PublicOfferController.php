<?php

declare(strict_types=1);

namespace Modules\Enrolment\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Enrolment\Services\OfferService;

/** The offer link sent by WhatsApp or SMS: see, accept (with consent) or decline. Signing the agreement then proves who it is with a PIN. */
final class PublicOfferController
{
    public function show(string $token): JsonResponse
    {
        return response()->json(['data' => $this->offers()->present($this->offers()->findByToken($token))]);
    }

    public function accept(Request $request, string $token): JsonResponse
    {
        $offer = $this->offers()->accept($this->offers()->findByToken($token), $request->all(), 'link', $request->ip());

        return response()->json(['data' => $this->offers()->present($offer)]);
    }

    public function decline(Request $request, string $token): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $offer = $this->offers()->findByToken($token);
        $this->offers()->decline($offer, $data['reason'] ?? null);

        return response()->json(['data' => $this->offers()->present($offer->refresh())]);
    }

    /** Resolved per request: controllers are reused, the tenant context is not. */
    private function offers(): OfferService
    {
        return app(OfferService::class);
    }
}
