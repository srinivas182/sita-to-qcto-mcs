<?php

declare(strict_types=1);

namespace Modules\Agreements\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Agreements\Models\Template;
use Modules\Agreements\Services\TemplateService;
use Modules\Agreements\Support\AgreementText;
use Modules\Agreements\Support\StarterTemplates;
use Modules\Identity\Models\User;

/** Agreement wording, versioned. */
final class TemplateController
{
    public function index(): JsonResponse
    {
        Gate::authorize('agreements.view');
        $all = Template::query()->orderByDesc('version')->get()->groupBy('family_id');

        return response()->json([
            'data' => $all->map(function ($versions): array {
                /** @var Template $latest */
                $latest = $versions->first();
                $published = $versions->firstWhere('status', 'published');

                return $this->present($latest) + [
                    'published_version' => $published?->version,
                    'published_id' => $published?->id,
                    'versions' => $versions->map(fn (Template $t) => ['id' => $t->id, 'version' => $t->version, 'status' => $t->status, 'published_at' => $t->published_at?->toIso8601String()])->values()->all(),
                ];
            })->sortBy('name')->values()->all(),
            'starters' => collect(StarterTemplates::all())->map(fn (array $s, string $k) => ['key' => $k, 'name' => $s['name'], 'kind' => $s['kind']])->values()->all(),
            'fields' => AgreementText::FIELDS,
        ]);
    }

    public function show(string $template): JsonResponse
    {
        Gate::authorize('agreements.view');

        return response()->json(['data' => $this->present(Template::query()->findOrFail($template)), 'fields' => AgreementText::FIELDS]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('agreements.manage');
        $service = app(TemplateService::class);
        $t = $request->filled('starter') ? $service->fromStarter((string) $request->input('starter')) : $service->save($request->all(), null);

        return response()->json(['data' => $this->present($t)], 201);
    }

    public function update(Request $request, string $template): JsonResponse
    {
        Gate::authorize('agreements.manage');
        $t = app(TemplateService::class)->save($request->all(), Template::query()->findOrFail($template));

        return response()->json(['data' => $this->present($t)]);
    }

    public function publish(Request $request, string $template): JsonResponse
    {
        Gate::authorize('agreements.manage');
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $this->present(app(TemplateService::class)->publish(Template::query()->findOrFail($template), $user))]);
    }

    public function retire(string $template): JsonResponse
    {
        Gate::authorize('agreements.manage');
        $t = Template::query()->findOrFail($template);
        app(TemplateService::class)->retire($t);

        return response()->json(['data' => $this->present($t->refresh())]);
    }

    /** @return array<string, mixed> */
    private function present(Template $t): array
    {
        return $t->only(['id', 'family_id', 'version', 'kind', 'name', 'body', 'parties', 'advanced_signature', 'status']) + [
            'published_at' => $t->published_at?->toIso8601String(),
            'updated_at' => $t->updated_at->toIso8601String(),
        ];
    }
}
