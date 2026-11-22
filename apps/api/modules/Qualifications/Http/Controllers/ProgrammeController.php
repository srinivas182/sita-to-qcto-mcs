<?php

declare(strict_types=1);

namespace Modules\Qualifications\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Identity\Models\User;
use Modules\Qualifications\Models\CentreQualification;
use Modules\Qualifications\Models\Programme;
use Modules\Qualifications\Models\Qualification;
use Modules\Qualifications\Models\QualificationModule;
use Modules\Qualifications\Models\Suggestion;
use Modules\Qualifications\Services\ProgrammeService;
use Modules\Tenancy\Context\TenantContext;
use Modules\Tenancy\Models\Centre;

/** A provider's view of the catalogue, its programmes, centre scope and suggestions. */
final class ProgrammeController
{
    /** The published catalogue (RLS shows providers nothing else). */
    public function catalogue(Request $request): JsonResponse
    {
        Gate::authorize('programmes.view');
        $query = Qualification::query()->where('status', 'published')->orderBy('title');
        if (($q = trim((string) $request->query('q', ''))) !== '') {
            $query->where(fn ($w) => $w->where('title', 'ilike', '%'.addcslashes($q, '%_\\').'%')->orWhere('saqa_id', $q)->orWhere('curriculum_code', $q));
        }
        if (($type = (string) $request->query('type', '')) === 'legacy') {
            $query->where('type', 'like', 'legacy_%');
        } elseif ($type === 'occupational') {
            $query->where('type', 'not like', 'legacy_%');
        }
        $offered = Programme::query()->pluck('qualification_id')->flip();
        $page = $query->withCount('modules')->paginate(50);

        return response()->json([
            'data' => collect($page->items())->map(fn (Qualification $x) => $x->summary() + ['modules' => $x->getAttribute('modules_count'), 'offered' => $offered->has($x->id)])->all(),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function qualification(string $qualification): JsonResponse
    {
        Gate::authorize('programmes.view');
        $q = Qualification::query()->where('status', 'published')->findOrFail($qualification);
        $successors = Qualification::query()->whereIn('id', $q->successor_ids ?? [])->get();

        return response()->json(['data' => $q->summary() + [
            'credit_rules' => $q->credit_rules,
            'modules' => $q->modules()->get()->map(fn (QualificationModule $m) => $m->present())->all(),
            'successors' => $successors->map(fn (Qualification $s) => $s->summary())->all(),
            'offered' => Programme::query()->where('qualification_id', $q->id)->exists(),
        ]]);
    }

    public function index(): JsonResponse
    {
        Gate::authorize('programmes.view');
        $programmes = Programme::query()->get();
        $qualifications = Qualification::query()->whereIn('id', $programmes->pluck('qualification_id'))->get()->keyBy('id');
        $centres = Centre::query()->pluck('name', 'id');
        $scope = CentreQualification::query()->get()->groupBy('qualification_id');

        return response()->json([
            'data' => $programmes->sortBy(fn (Programme $p) => $qualifications->get($p->qualification_id)?->title)->values()
                ->map(fn (Programme $p) => [
                    'id' => $p->id, 'active' => $p->active, 'modes' => $p->modes ?? [], 'price_cents' => $p->price_cents, 'notes' => $p->notes,
                    'qualification' => $qualifications->get($p->qualification_id)?->summary(),
                    'centres' => ($scope->get($p->qualification_id) ?? collect())->map(fn ($l) => ['id' => $l->centre_id, 'name' => $centres->get($l->centre_id)])->values()->all(),
                ])->all(),
            'suggested' => $this->service()->suggested()->map(fn (Qualification $q) => $q->summary())->values()->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('programmes.manage');
        $programme = $this->service()->adopt($request->all());

        return response()->json(['data' => ['id' => $programme->id]], 201);
    }

    public function update(Request $request, string $programme): JsonResponse
    {
        Gate::authorize('programmes.manage');
        $model = $this->service()->update(Programme::query()->findOrFail($programme), $request->all());

        return response()->json(['data' => ['id' => $model->id, 'active' => $model->active]]);
    }

    public function centreScope(string $centre): JsonResponse
    {
        Gate::authorize('centres.view');

        return response()->json(['data' => $this->service()->centreScope(Centre::query()->findOrFail($centre))]);
    }

    public function setCentreScope(Request $request, string $centre): JsonResponse
    {
        Gate::authorize('programmes.manage');
        $model = Centre::query()->findOrFail($centre);
        /** @var list<array<string, mixed>> $items */
        $items = (array) $request->input('items', []);
        $this->service()->setCentreScope($model, $items);

        return response()->json(['data' => $this->service()->centreScope($model)]);
    }

    public function suggestions(): JsonResponse
    {
        Gate::authorize('programmes.view');

        return response()->json(['data' => Suggestion::query()->latest()->limit(50)->get()
            ->map(fn (Suggestion $s) => $s->only(['id', 'kind', 'title', 'saqa_id', 'status', 'response']) + ['created_at' => $s->created_at->toIso8601String()])->all()]);
    }

    /** Tell the catalogue editors about a missing qualification or a mistake. */
    public function suggest(Request $request): JsonResponse
    {
        Gate::authorize('programmes.manage');
        $data = $request->validate([
            'kind' => ['required', 'in:new,correction'],
            'qualification_id' => ['required_if:kind,correction', 'nullable', 'ulid', 'exists:qualifications,id'],
            'saqa_id' => ['nullable', 'regex:/^\d{4,8}$/'],
            'title' => ['required', 'string', 'min:5', 'max:300'],
            'details' => ['required', 'string', 'min:10', 'max:5000'],
            'file_id' => ['nullable', 'ulid', 'exists:files,id'],
        ]);
        /** @var User $user */
        $user = $request->user();
        $s = Suggestion::query()->create($data + ['submitted_by' => $user->id, 'tenant_id' => app(TenantContext::class)->tenantId()]);

        return response()->json(['data' => ['id' => $s->id, 'status' => $s->status]], 201);
    }

    private function service(): ProgrammeService
    {
        return app(ProgrammeService::class);
    }
}
