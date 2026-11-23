<?php

declare(strict_types=1);

namespace Modules\Delivery\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Delivery\Models\Material;
use Modules\Delivery\Services\MaterialService;
use Modules\Enrolment\Models\Enrolment;
use Modules\Files\Models\File;
use Modules\Files\Storage\ContentDisposition;
use Modules\Files\Storage\StoreFactory;
use Modules\Identity\Models\User;
use Modules\Qualifications\Models\QualificationModule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Course materials: staff manage them per qualification; learners reach them through their enrolment. */
final class MaterialController
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('materials.manage');
        $data = $request->validate(['qualification_id' => ['required', 'ulid']]);
        $items = Material::query()->where('qualification_id', $data['qualification_id'])->orderBy('sort')->orderBy('title')->get();
        $files = File::query()->whereIn('id', $items->pluck('file_id')->merge($items->pluck('low_file_id'))->filter())->get()->keyBy('id');

        return response()->json(['data' => $items->map(fn (Material $m) => $m->only(['id', 'module_id', 'title', 'description', 'kind', 'url', 'file_id', 'status', 'version', 'low_status', 'duration_s', 'sort']) + [
            'size' => $m->file_id ? $files->get($m->file_id)?->size : null, 'low_size' => $m->low_file_id ? $files->get($m->low_file_id)?->size : null,
            'file_name' => $m->file_id ? $files->get($m->file_id)?->original_name : null,
        ])->values()->all(), 'modules' => QualificationModule::query()->where('qualification_id', $data['qualification_id'])->orderBy('sort')->get(['id', 'code', 'title', 'kind'])->values()->all(),
            'kinds' => Material::KINDS]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('materials.manage');
        /** @var User $by */
        $by = $request->user();

        return response()->json(['data' => $this->materials()->save(null, $request->all(), $by)], 201);
    }

    public function update(Request $request, string $material): JsonResponse
    {
        Gate::authorize('materials.manage');
        /** @var User $by */
        $by = $request->user();

        return response()->json(['data' => $this->materials()->save(Material::query()->whereKey($material)->firstOrFail(), $request->all(), $by)]);
    }

    public function publish(Request $request, string $material): JsonResponse
    {
        Gate::authorize('materials.manage');
        $data = $request->validate(['published' => ['required', 'boolean']]);

        return response()->json(['data' => $this->materials()->publish(Material::query()->whereKey($material)->firstOrFail(), (bool) $data['published'])]);
    }

    public function destroy(string $material): JsonResponse
    {
        Gate::authorize('materials.manage');
        $this->materials()->delete(Material::query()->whereKey($material)->firstOrFail());

        return response()->json(null, 204);
    }

    public function forCourse(Request $request, string $enrolment): JsonResponse
    {
        return response()->json(['data' => $this->materials()->forEnrolment($this->mine($request, $enrolment))]);
    }

    /** What the app should hold for offline use; it fetches only items whose version changed. */
    public function offlinePack(Request $request, string $enrolment): JsonResponse
    {
        $data = $this->materials()->forEnrolment($this->mine($request, $enrolment));
        $low = $request->boolean('low', true);
        $items = array_values(array_filter($data['materials'], fn (array $m) => $m['kind'] !== 'link'));
        $items = array_map(fn (array $m) => ['id' => $m['id'], 'version' => $m['version'], 'kind' => $m['kind'], 'title' => $m['title'], 'mime' => $m['mime'],
            'size' => $m['kind'] === 'video' && $low && $m['low_size'] ? $m['low_size'] : $m['size'], 'low' => $m['kind'] === 'video' && $low && $m['low_size'] !== null], $items);

        return response()->json(['data' => ['items' => $items, 'total_bytes' => array_sum(array_map(fn ($i) => (int) $i['size'], $items))]]);
    }

    public function download(Request $request, string $material): JsonResponse
    {
        /** @var User $me */
        $me = $request->user();
        $m = Material::query()->whereKey($material)->firstOrFail();
        $low = $request->boolean('low');
        $r = $this->materials()->download($m, $me, $low);
        if ($r['url'] === null) {
            $r = ['url' => url("/v1/me/materials/{$m->id}/content").($low ? '?low=1' : ''), 'via' => 'api'];
        }

        return response()->json(['data' => $r + ['version' => $m->version]]);
    }

    /** For stores without signed links (local disk): the bytes, after the same check. */
    public function content(Request $request, string $material): StreamedResponse
    {
        /** @var User $me */
        $me = $request->user();
        $m = Material::query()->whereKey($material)->firstOrFail();
        $this->materials()->mayOpen($m, $me);
        $file = File::query()->whereKey($request->boolean('low') && $m->low_file_id ? $m->low_file_id : $m->file_id)->firstOrFail();
        $disk = app(StoreFactory::class)->forFile($file)->disk();

        return response()->stream(function () use ($disk, $file): void {
            $stream = $disk->readStream($file->path);
            if ($stream !== null) {
                fpassthru($stream);
                fclose($stream);
            }
        }, 200, ['Content-Type' => $file->mime, 'Content-Length' => (string) $file->size, 'Content-Disposition' => ContentDisposition::attachment($file->original_name),
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    public function progress(Request $request, string $material): JsonResponse
    {
        $data = $request->validate(['completed' => ['required', 'boolean']]);
        /** @var User $me */
        $me = $request->user();
        $p = $this->materials()->complete(Material::query()->whereKey($material)->firstOrFail(), $me, (bool) $data['completed']);

        return response()->json(['data' => ['completed' => $p->completed_at !== null]]);
    }

    private function mine(Request $request, string $id): Enrolment
    {
        /** @var User $me */
        $me = $request->user();

        return Enrolment::query()->whereKey($id)->where('user_id', $me->id)->where('status', 'active')->firstOrFail();
    }

    private function materials(): MaterialService
    {
        return app(MaterialService::class);
    }
}
