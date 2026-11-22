<?php

declare(strict_types=1);

namespace Modules\Qualifications\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Identity\Models\User;
use Modules\Qualifications\Models\Qualification;
use Modules\Qualifications\Models\QualificationModule;
use Modules\Qualifications\Models\Suggestion;
use Modules\Qualifications\Services\CatalogueService;
use Modules\Tenancy\Models\Tenant;

/** Catalogue editors: the shared qualification catalogue and providers' suggestions. Platform context. */
final class QualificationAdminController
{
    public function index(Request $request): JsonResponse
    {
        $query = Qualification::query()->orderBy('title');
        if (($status = (string) $request->query('status', 'all')) !== 'all') {
            $query->where('status', $status);
        }
        if (($type = (string) $request->query('type', '')) !== '') {
            $query->where('type', $type);
        }
        if (($q = trim((string) $request->query('q', ''))) !== '') {
            $query->where(fn ($w) => $w->where('title', 'ilike', '%'.addcslashes($q, '%_\\').'%')->orWhere('saqa_id', $q)->orWhere('curriculum_code', $q));
        }
        if ($request->boolean('closing')) {
            $query->where('status', 'published')->whereNotNull('last_enrolment_on')
                ->whereDate('last_enrolment_on', '<=', now()->addDays(Qualification::CLOSING_DAYS));
        }
        $page = $query->withCount('modules')->paginate(50);

        return response()->json([
            'data' => collect($page->items())->map(fn (Qualification $x) => $x->summary() + ['modules' => $x->getAttribute('modules_count')])->all(),
            'counts' => [
                'draft' => Qualification::query()->where('status', 'draft')->count(),
                'published' => Qualification::query()->where('status', 'published')->count(),
                'closing' => Qualification::query()->where('status', 'published')->whereNotNull('last_enrolment_on')
                    ->whereDate('last_enrolment_on', '<=', now()->addDays(Qualification::CLOSING_DAYS))->whereDate('last_enrolment_on', '>=', now())->count(),
                'suggestions' => Suggestion::query()->where('status', 'open')->count(),
            ],
            'types' => Qualification::TYPES,
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function show(string $qualification): JsonResponse
    {
        $q = Qualification::query()->findOrFail($qualification);

        return response()->json(['data' => $this->detail($q)]);
    }

    public function store(Request $request): JsonResponse
    {
        $q = $this->service()->save($request->all(), null, $this->actor($request));

        return response()->json(['data' => $this->detail($q)], 201);
    }

    public function update(Request $request, string $qualification): JsonResponse
    {
        $q = $this->service()->save($request->all(), Qualification::query()->findOrFail($qualification), $this->actor($request));

        return response()->json(['data' => $this->detail($q)]);
    }

    /** Paste modules: preview with `apply=false`, then save with `apply=true` (replaces them all). */
    public function importModules(Request $request, string $qualification): JsonResponse
    {
        $data = $request->validate(['text' => ['required', 'string', 'max:200000'], 'apply' => ['sometimes', 'boolean']]);
        $q = Qualification::query()->findOrFail($qualification);
        $result = $this->service()->importModules($q, $data['text'], (bool) ($data['apply'] ?? false), $this->actor($request));

        return response()->json($result + ['data' => $this->detail($q->refresh())]);
    }

    public function publish(Request $request, string $qualification): JsonResponse
    {
        $q = $this->service()->publish(Qualification::query()->findOrFail($qualification), $this->actor($request));

        return response()->json(['data' => $this->detail($q)]);
    }

    public function withdraw(Request $request, string $qualification): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $q = $this->service()->withdraw(Qualification::query()->findOrFail($qualification), $data['reason'], $this->actor($request));

        return response()->json(['data' => $this->detail($q)]);
    }

    public function suggestions(Request $request): JsonResponse
    {
        $status = (string) $request->query('status', 'open');
        $rows = Suggestion::query()->when($status !== 'all', fn ($q) => $q->where('status', $status))->latest()->limit(100)->get();
        $tenants = Tenant::query()->whereIn('id', $rows->pluck('tenant_id'))->pluck('trading_name', 'id');

        return response()->json(['data' => $rows->map(fn (Suggestion $s) => [
            'id' => $s->id, 'kind' => $s->kind, 'title' => $s->title, 'saqa_id' => $s->saqa_id, 'details' => $s->details,
            'qualification_id' => $s->qualification_id, 'file_id' => $s->file_id, 'status' => $s->status, 'response' => $s->response,
            'provider' => $tenants->get($s->tenant_id), 'created_at' => $s->created_at->toIso8601String(),
        ])->all()]);
    }

    public function decide(Request $request, string $suggestion): JsonResponse
    {
        $data = $request->validate([
            'accept' => ['required', 'boolean'],
            'response' => ['required', 'string', 'min:5', 'max:2000'],
            'qualification_id' => ['nullable', 'ulid', 'exists:qualifications,id'],
        ]);
        $s = $this->service()->decide(Suggestion::query()->findOrFail($suggestion), (bool) $data['accept'], $data['response'], $data['qualification_id'] ?? null, $this->actor($request));

        return response()->json(['data' => ['id' => $s->id, 'status' => $s->status]]);
    }

    /** @return array<string, mixed> */
    private function detail(Qualification $q): array
    {
        $modules = $q->modules()->get();
        $successors = Qualification::query()->whereIn('id', $q->successor_ids ?? [])->get(['id', 'title', 'saqa_id', 'status']);

        return $q->summary() + [
            'credit_rules' => $q->credit_rules,
            'source_url' => $q->source_url,
            'notes' => $q->notes,
            'published_at' => $q->published_at?->toIso8601String(),
            'modules' => $modules->map(fn (QualificationModule $m) => $m->present())->all(),
            'successors' => $successors->map(fn (Qualification $s) => $s->only(['id', 'title', 'saqa_id', 'status']))->all(),
            'checks' => $this->service()->checks($q),
        ];
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }

    private function service(): CatalogueService
    {
        return app(CatalogueService::class);
    }
}
