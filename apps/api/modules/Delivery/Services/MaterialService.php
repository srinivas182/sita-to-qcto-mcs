<?php

declare(strict_types=1);

namespace Modules\Delivery\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Audit\AuditLogger;
use Modules\Cohorts\Models\Cohort;
use Modules\Delivery\Jobs\MakeLowDataVideo;
use Modules\Delivery\Models\Material;
use Modules\Delivery\Models\MaterialProgress;
use Modules\Enrolment\Models\Enrolment;
use Modules\Enrolment\Services\EnrolmentService;
use Modules\Files\Models\File;
use Modules\Files\Services\FileService;
use Modules\Identity\Models\User;
use Modules\Qualifications\Models\QualificationModule;

/**
 * Course materials per qualification and module, published versions, and the one way a
 * learner reaches them: an active enrolment in a cohort for that qualification.
 */
final class MaterialService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param  array<string, mixed>  $input */
    public function save(?Material $material, array $input, User $by): Material
    {
        $creating = $material === null;
        $data = Validator::make($input, [
            'qualification_id' => [$creating ? 'required' : 'prohibited', 'ulid'],
            'module_id' => ['nullable', 'ulid'],
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'min:2', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'kind' => [$creating ? 'required' : 'sometimes', Rule::in(array_keys(Material::KINDS))],
            'file_id' => ['nullable', 'ulid'],
            'url' => ['nullable', 'url:https', 'max:500'],
            'sort' => ['sometimes', 'integer', 'between:0,10000'],
        ], ['url.url' => 'Use a full https:// address.'])->validate();

        $m = $material ?? new Material(['created_by' => $by->id]);
        $qualification = $creating ? (string) $data['qualification_id'] : $m->qualification_id;
        if (! empty($data['module_id']) && ! QualificationModule::query()->whereKey($data['module_id'])->where('qualification_id', $qualification)->exists()) {
            throw ValidationException::withMessages(['module_id' => 'Choose a module of this qualification.']);
        }
        $kind = (string) ($data['kind'] ?? $m->kind);
        $fileId = array_key_exists('file_id', $data) ? $data['file_id'] : $m->file_id;
        $url = array_key_exists('url', $data) ? $data['url'] : $m->url;
        if ($kind === 'link') {
            if (! $url) {
                throw ValidationException::withMessages(['url' => 'Add the address.']);
            }
            $fileId = null;
        } else {
            $file = $fileId ? File::query()->whereKey($fileId)->where('purpose', 'learning_material')->first() : null;
            if ($file === null || ! $file->isReady()) {
                throw ValidationException::withMessages(['file_id' => 'Upload the file first.']);
            }
            $wanted = ['document' => ['application/pdf'], 'image' => ['image/jpeg', 'image/png'], 'video' => ['video/mp4', 'video/quicktime', 'video/webm']][$kind];
            if (! in_array($file->mime, $wanted, true)) {
                throw ValidationException::withMessages(['file_id' => 'That file is not a '.strtolower(Material::KINDS[$kind]).'.']);
            }
            $url = null;
        }
        $newFile = $fileId !== $m->file_id;
        $m->fill(array_intersect_key($data, array_flip(['qualification_id', 'module_id', 'title', 'description', 'kind', 'sort'])) + ['file_id' => $fileId, 'url' => $url]);
        if (! $creating && ($newFile || $m->isDirty('url'))) {
            $m->version++; // phones that downloaded the old one fetch it again
        }
        if ($newFile) {
            $m->forceFill(['low_file_id' => null, 'low_status' => $kind === 'video' ? 'queued' : null, 'duration_s' => null]);
        }
        $m->save();
        if ($newFile && $kind === 'video') {
            MakeLowDataVideo::dispatch($m->id);
        }
        $this->audit->record($creating ? 'material.created' : 'material.changed', $m);

        return $m;
    }

    public function publish(Material $m, bool $published): Material
    {
        $m->forceFill(['status' => $published ? 'published' : 'draft'])->save();
        $this->audit->record($published ? 'material.published' : 'material.unpublished', $m);

        return $m;
    }

    public function delete(Material $m): void
    {
        $m->delete();
        $this->audit->record('material.deleted', null, [], ['title' => $m->title]);
    }

    /**
     * The published materials of an enrolment's qualification, by module, with the learner's progress.
     *
     * @return array{modules: list<mixed>, materials: list<array<string, mixed>>}
     */
    public function forEnrolment(Enrolment $e): array
    {
        $qualification = (string) Cohort::query()->whereKey($e->cohort_id)->value('qualification_id');
        $items = Material::query()->where('qualification_id', $qualification)->where('status', 'published')->orderBy('sort')->orderBy('title')->get();
        $progress = MaterialProgress::query()->where('user_id', $e->user_id)->whereIn('material_id', $items->pluck('id'))->get()->keyBy('material_id');
        $files = File::query()->whereIn('id', $items->pluck('file_id')->merge($items->pluck('low_file_id'))->filter())->get()->keyBy('id');
        $modules = QualificationModule::query()->where('qualification_id', $qualification)->orderBy('sort')->get(['id', 'code', 'title', 'kind']);

        return ['modules' => array_values($modules->all()), 'materials' => array_values($items->map(fn (Material $m) => [
            'id' => $m->id, 'module_id' => $m->module_id, 'title' => $m->title, 'description' => $m->description, 'kind' => $m->kind, 'url' => $m->kind === 'link' ? $m->url : null,
            'version' => $m->version, 'size' => $m->file_id ? $files->get($m->file_id)?->size : null, 'mime' => $m->file_id ? $files->get($m->file_id)?->mime : null,
            'low_size' => $m->low_file_id ? $files->get($m->low_file_id)?->size : null, 'duration_s' => $m->duration_s,
            'opened' => $progress->get($m->id)?->opened_at !== null, 'completed' => $progress->get($m->id)?->completed_at !== null,
        ])->all())];
    }

    /** @return array{url: string|null, via: string} where to fetch a material's file, after the enrolment check */
    public function download(Material $m, User $learner, bool $low): array
    {
        $this->mayOpen($m, $learner);
        $fileId = $low && $m->low_file_id ? $m->low_file_id : $m->file_id;
        $file = File::query()->whereKey($fileId)->firstOrFail();
        $p = MaterialProgress::query()->firstOrCreate(['material_id' => $m->id, 'user_id' => $learner->id]);
        if ($p->opened_at === null) {
            $p->forceFill(['opened_at' => now()])->save();
        }

        return app(FileService::class)->download($file);
    }

    public function complete(Material $m, User $learner, bool $done): MaterialProgress
    {
        $this->mayOpen($m, $learner);
        $p = MaterialProgress::query()->firstOrCreate(['material_id' => $m->id, 'user_id' => $learner->id]);
        $p->forceFill(['opened_at' => $p->opened_at ?? now(), 'completed_at' => $done ? ($p->completed_at ?? now()) : null])->save();

        return $p;
    }

    /** The access rule (ADR-021): only with an active enrolment for the qualification, and only published. */
    public function mayOpen(Material $m, User $learner): void
    {
        if ($m->status !== 'published' || ! EnrolmentService::mayLearn($learner->id, $m->qualification_id)) {
            abort(404);
        }
    }
}
