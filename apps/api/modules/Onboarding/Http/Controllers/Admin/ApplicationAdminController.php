<?php

declare(strict_types=1);

namespace Modules\Onboarding\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Files\Models\File;
use Modules\Files\Services\FileService;
use Modules\Files\Storage\ContentDisposition;
use Modules\Files\Storage\StoreFactory;
use Modules\Identity\Models\User;
use Modules\Onboarding\Models\Application;
use Modules\Onboarding\Models\ApplicationFile;
use Modules\Onboarding\Services\ApplicationService;
use Symfony\Component\HttpFoundation\Response;

/** Super Admin: the applications queue, review checklist and decisions. Platform context. */
final class ApplicationAdminController
{
    public function index(Request $request): JsonResponse
    {
        $status = (string) $request->query('status', 'submitted');
        $query = Application::query()->orderByRaw('submitted_at asc nulls last')->orderByDesc('created_at');
        if ($status !== 'all') {
            $query->where('status', $status);
        }
        $page = $query->paginate(25);

        return response()->json([
            'data' => collect($page->items())->map(fn (Application $a) => [
                'id' => $a->id,
                'reference' => $a->reference,
                'status' => $a->status,
                'legal_name' => $a->legal_name,
                'contact' => $a->first_name.' '.$a->last_name,
                'email' => $a->email,
                'sdp_number' => $a->sdp_number,
                'province' => $a->province,
                'submitted_at' => $a->submitted_at?->toIso8601String(),
                'created_at' => $a->created_at->toIso8601String(),
            ])->all(),
            'counts' => Application::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function show(string $application): JsonResponse
    {
        return response()->json(['data' => $this->service()->present(Application::query()->findOrFail($application), true)]);
    }

    public function review(Request $request, string $application): JsonResponse
    {
        $data = $request->validate(['checklist' => ['sometimes', 'array'], 'checklist.*' => ['boolean'], 'notes' => ['nullable', 'string', 'max:5000']]);
        /** @var array<string, bool> $checklist */
        $checklist = $data['checklist'] ?? [];
        $model = $this->service()->review(Application::query()->findOrFail($application), $checklist, $data['notes'] ?? null, $this->actor($request));

        return response()->json(['data' => $this->service()->present($model, true)]);
    }

    public function approve(Request $request, string $application): JsonResponse
    {
        $tenant = $this->service()->approve(Application::query()->findOrFail($application), $this->actor($request));

        return response()->json(['tenant' => ['id' => $tenant->id, 'code' => $tenant->code, 'name' => $tenant->trading_name]], 201);
    }

    public function requestInfo(Request $request, string $application): JsonResponse
    {
        $data = $request->validate(['message' => ['required', 'string', 'min:10', 'max:1000']]);
        $model = $this->service()->requestInfo(Application::query()->findOrFail($application), $data['message'], $this->actor($request));

        return response()->json(['data' => $this->service()->present($model, true)]);
    }

    public function decline(Request $request, string $application): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:1000']]);
        $model = $this->service()->decline(Application::query()->findOrFail($application), $data['reason'], $this->actor($request));

        return response()->json(['data' => $this->service()->present($model, true)]);
    }

    /** View an uploaded document (only clean, checked files). */
    public function file(string $application, string $file): Response
    {
        abort_unless(ApplicationFile::query()->where('application_id', $application)->where('file_id', $file)->exists(), 404);
        $model = File::query()->findOrFail($file);
        $link = app(FileService::class)->download($model);

        if ($link['url'] !== null) {
            return redirect()->away($link['url']);
        }

        $bytes = (string) app(StoreFactory::class)->forFile($model)->disk()->get($model->path);

        return response($bytes, 200, [
            'Content-Type' => $model->mime,
            'Content-Disposition' => str_replace('attachment;', 'inline;', ContentDisposition::attachment($model->original_name)),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'",
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }

    private function service(): ApplicationService
    {
        return app(ApplicationService::class);
    }
}
