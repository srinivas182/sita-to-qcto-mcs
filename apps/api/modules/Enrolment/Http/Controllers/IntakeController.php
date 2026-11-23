<?php

declare(strict_types=1);

namespace Modules\Enrolment\Http\Controllers;

use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Access\Services\AccessService;
use Modules\Cohorts\Models\Cohort;
use Modules\Enrolment\Models\Intake;
use Modules\Enrolment\Services\IntakeService;
use Modules\Identity\Models\User;
use Modules\Qualifications\Models\Qualification;
use Modules\Tenancy\Context\TenantContext;
use Modules\Tenancy\Models\Tenant;

/** Intakes: cohorts published for applications. */
final class IntakeController
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('applications.view');
        $query = Intake::query()->orderByDesc('opens_on');
        if (($centres = $this->centres($request, 'applications.view')) !== null) {
            $query->whereIn('cohort_id', Cohort::query()->whereIn('centre_id', $centres)->select('id'));
        }
        $intakes = $query->limit(200)->get();
        $counts = DB::table('applications')->whereIn('intake_id', $intakes->pluck('id'))->groupBy('intake_id', 'status')
            ->selectRaw('intake_id, status, count(*) as n')->get()->groupBy('intake_id');
        $cohorts = Cohort::query()->whereIn('id', $intakes->pluck('cohort_id'))->get()->keyBy('id');
        $quals = Qualification::query()->whereIn('id', $cohorts->pluck('qualification_id'))->pluck('title', 'id');

        return response()->json(['data' => $intakes->map(fn (Intake $i) => $this->present($i) + [
            'cohort' => $cohorts->get($i->cohort_id)?->name,
            'qualification' => $quals->get((string) $cohorts->get($i->cohort_id)?->qualification_id),
            'counts' => ($counts->get($i->id) ?? collect())->pluck('n', 'status')->map(fn ($n) => (int) $n),
        ])->values()->all()]);
    }

    public function show(Request $request, string $intake): JsonResponse
    {
        Gate::authorize('applications.view');

        $i = $this->find($request, $intake, 'applications.view');
        $present = $this->present($i);
        // A QR code of the public link, for posters and open days.
        $qr = (new QRCode(new QROptions(['outputType' => QROutputInterface::GDIMAGE_PNG, 'outputBase64' => true, 'scale' => 8, 'quietzoneSize' => 2])))->render($present['url']);

        return response()->json(['data' => $present + ['qr' => $qr]]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('applications.manage');
        $i = app(IntakeService::class)->save($request->all(), null, $this->centres($request, 'applications.manage'), $this->user($request));

        return response()->json(['data' => $this->present($i)], 201);
    }

    public function update(Request $request, string $intake): JsonResponse
    {
        Gate::authorize('applications.manage');
        $i = app(IntakeService::class)->save($request->all(), $this->find($request, $intake, 'applications.manage'), $this->centres($request, 'applications.manage'), $this->user($request));

        return response()->json(['data' => $this->present($i)]);
    }

    public function publish(Request $request, string $intake): JsonResponse
    {
        Gate::authorize('applications.manage');

        return response()->json(['data' => $this->present(app(IntakeService::class)->publish($this->find($request, $intake, 'applications.manage')))]);
    }

    public function close(Request $request, string $intake): JsonResponse
    {
        Gate::authorize('applications.manage');

        return response()->json(['data' => $this->present(app(IntakeService::class)->close($this->find($request, $intake, 'applications.manage')))]);
    }

    /** @return array<string, mixed> */
    private function present(Intake $i): array
    {
        /** @var Tenant $tenant */
        $tenant = app(TenantContext::class)->runAsPlatform(fn () => Tenant::query()->whereKey($i->tenant_id)->firstOrFail());

        return $i->only(['id', 'cohort_id', 'slug', 'title', 'description', 'places', 'min_age', 'max_age', 'document_types', 'questions', 'status']) + [
            'opens_on' => $i->opens_on->toDateString(), 'closes_on' => $i->closes_on->toDateString(), 'accepting' => $i->accepting(),
            'url' => 'https://'.$tenant->code.'.'.config('platform.base_domain').'/join/'.$i->slug,
        ];
    }

    private function find(Request $request, string $id, string $permission): Intake
    {
        $intake = Intake::query()->whereKey($id)->firstOrFail();
        $centres = $this->centres($request, $permission);
        abort_if($centres !== null && ! in_array(Cohort::query()->whereKey($intake->cohort_id)->value('centre_id'), $centres, true), 404);

        return $intake;
    }

    /** @return list<string>|null */
    private function centres(Request $request, string $permission): ?array
    {
        return app(AccessService::class)->centresFor($this->user($request), $permission);
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
