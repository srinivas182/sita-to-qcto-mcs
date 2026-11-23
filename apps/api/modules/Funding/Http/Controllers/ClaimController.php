<?php

declare(strict_types=1);

namespace Modules\Funding\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Modules\Access\Services\AccessService;
use Modules\Cohorts\Models\Cohort;
use Modules\Funding\Models\ClaimQuery;
use Modules\Funding\Models\GrantAgreement;
use Modules\Funding\Models\GrantClaim;
use Modules\Funding\Models\GrantTranche;
use Modules\Funding\Services\ClaimPack;
use Modules\Funding\Services\ClaimService;
use Modules\Identity\Models\User;

/** Tranche claims: create, pack, send, queries and fixes, paid or rejected. */
final class ClaimController
{
    public function index(Request $request, string $agreement): JsonResponse
    {
        $a = $this->agreement($request, $agreement, 'funding.view');

        return response()->json(['data' => GrantClaim::query()->where('agreement_id', $a->id)->orderByDesc('created_at')->get()
            ->map(fn (GrantClaim $c) => ClaimService::present($c))->all()]);
    }

    public function create(Request $request, string $tranche): JsonResponse
    {
        $t = GrantTranche::query()->whereKey($tranche)->firstOrFail();
        $this->agreement($request, $t->agreement_id, 'funding.manage');
        /** @var User $me */
        $me = $request->user();

        return response()->json(['data' => ClaimService::present(app(ClaimService::class)->create($t, $me, $request->all()))], 201);
    }

    public function show(Request $request, string $claim): JsonResponse
    {
        $c = $this->claim($request, $claim, 'funding.view');

        return response()->json(['data' => ClaimService::present($c)]);
    }

    public function pack(Request $request, string $claim): Response
    {
        $c = $this->claim($request, $claim, 'funding.manage');

        return response(app(ClaimPack::class)->zip($c), 200, ['Content-Type' => 'application/zip', 'Cache-Control' => 'no-store',
            'Content-Disposition' => 'attachment; filename="'.$c->number.'.zip"']);
    }

    public function sent(Request $request, string $claim): JsonResponse
    {
        $c = $this->claim($request, $claim, 'funding.manage');

        return response()->json(['data' => ClaimService::present(app(ClaimService::class)->sent($c, $request->all()))]);
    }

    public function query(Request $request, string $claim): JsonResponse
    {
        $c = $this->claim($request, $claim, 'funding.manage');
        /** @var User $me */
        $me = $request->user();
        app(ClaimService::class)->query($c, $me, $request->all());

        return response()->json(['data' => ClaimService::present($c->refresh())], 201);
    }

    public function resolve(Request $request, string $query): JsonResponse
    {
        $q = ClaimQuery::query()->whereKey($query)->firstOrFail();
        $c = $this->claim($request, $q->claim_id, 'funding.manage');
        /** @var User $me */
        $me = $request->user();
        app(ClaimService::class)->resolve($q, $me, $request->all());

        return response()->json(['data' => ClaimService::present($c)]);
    }

    public function paid(Request $request, string $claim): JsonResponse
    {
        $c = $this->claim($request, $claim, 'funding.manage');

        return response()->json(['data' => ClaimService::present(app(ClaimService::class)->paid($c, $request->all()))]);
    }

    public function rejected(Request $request, string $claim): JsonResponse
    {
        $c = $this->claim($request, $claim, 'funding.manage');

        return response()->json(['data' => ClaimService::present(app(ClaimService::class)->close($c, 'rejected', $request->all()))]);
    }

    public function void(Request $request, string $claim): JsonResponse
    {
        $c = $this->claim($request, $claim, 'funding.manage');

        return response()->json(['data' => ClaimService::present(app(ClaimService::class)->close($c, 'void', $request->all()))]);
    }

    private function claim(Request $request, string $id, string $permission): GrantClaim
    {
        $c = GrantClaim::query()->whereKey($id)->firstOrFail();
        $this->agreement($request, $c->agreement_id, $permission);

        return $c;
    }

    private function agreement(Request $request, string $id, string $permission): GrantAgreement
    {
        Gate::authorize($permission);
        $a = GrantAgreement::query()->whereKey($id)->firstOrFail();
        $c = Cohort::query()->whereKey($a->cohort_id)->firstOrFail();
        /** @var User $user */
        $user = $request->user();
        $centres = app(AccessService::class)->centresFor($user, $permission);
        abort_unless($centres === null || in_array($c->centre_id, $centres, true), 404);

        return $a;
    }
}
