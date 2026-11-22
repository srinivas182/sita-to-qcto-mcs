<?php

declare(strict_types=1);

namespace Modules\Files\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Modules\Files\Exceptions\FileError;
use Modules\Files\Models\File;
use Modules\Files\Services\FileService;
use Modules\Files\Storage\ContentDisposition;
use Modules\Files\Storage\LocalStore;
use Modules\Files\Storage\StoreFactory;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Uploads inside a training provider (the purpose decides who may). */
final class FileController
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'purpose' => ['required', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:255'],
            'mime' => ['required', 'string', 'max:100'],
            'size' => ['required', 'integer', 'min:1'],
        ]);

        $purpose = (array) config("files.purposes.{$data['purpose']}", []);
        if (($purpose['scope'] ?? null) !== 'tenant') {
            throw new FileError('Unknown file purpose.', 'unknown_purpose');
        }
        Gate::authorize((string) $purpose['permission']);

        /** @var array{purpose: string, name: string, mime: string, size: int} $data */
        ['file' => $file, 'upload' => $upload] = $this->files()->create($data, $request->user()?->getAuthIdentifier());

        return response()->json([
            'data' => $this->files()->present($file),
            'upload' => $upload?->toArray() ?? [
                'method' => 'PUT',
                'url' => url("/v1/files/{$file->id}/content"),
                'headers' => (object) ['Content-Type' => $file->mime],
                'via' => 'api',
            ],
        ], 201);
    }

    /** Local storage only: the bytes come through the API. */
    public function upload(Request $request, string $file): JsonResponse
    {
        $model = $this->find($file);

        if (! $this->stores()->forFile($model) instanceof LocalStore) {
            throw new FileError('Upload this file straight to storage with the link you were given.', 'direct_upload_required');
        }

        $body = $request->getContent(true);
        $this->files()->receive($model, $body);

        return response()->json(['data' => $this->files()->present($model)]);
    }

    public function complete(string $file): JsonResponse
    {
        $model = $this->files()->complete($this->find($file));

        return response()->json(['data' => $this->files()->present($model->refresh())]);
    }

    public function show(string $file): JsonResponse
    {
        return response()->json(['data' => $this->files()->present($this->find($file))]);
    }

    public function download(string $file): JsonResponse
    {
        $model = $this->find($file);
        $link = $this->files()->download($model);

        return response()->json($link['url'] !== null ? $link : ['url' => url("/v1/files/{$model->id}/content"), 'via' => 'api']);
    }

    /** Streams a ready file from local storage. */
    public function content(string $file): StreamedResponse
    {
        $model = $this->find($file);

        if (! $model->isReady()) {
            throw new FileError('This file is not available yet.', 'not_ready', 409);
        }

        $disk = $this->stores()->forFile($model)->disk();

        return response()->stream(function () use ($disk, $model): void {
            $stream = $disk->readStream($model->path);
            if ($stream !== null) {
                fpassthru($stream);
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => $model->mime,
            'Content-Length' => (string) $model->size,
            'Content-Disposition' => ContentDisposition::attachment($model->original_name),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function destroy(string $file): Response
    {
        $this->files()->delete($this->find($file));

        return response()->noContent();
    }

    /** Find a file in the current provider and check the purpose's permission. */
    private function find(string $id): File
    {
        $file = File::query()->findOrFail($id);
        Gate::authorize((string) ($file->purposeConfig()['permission'] ?? 'tenant.settings.manage'));

        return $file;
    }

    private function files(): FileService
    {
        return app(FileService::class);
    }

    private function stores(): StoreFactory
    {
        return app(StoreFactory::class);
    }
}
