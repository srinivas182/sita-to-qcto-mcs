<?php

declare(strict_types=1);

namespace Modules\Files\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Files\Models\File;
use Modules\Files\Storage\StoreFactory;
use Modules\Files\Support\FileStatus;

/**
 * Public image variants (e.g. a provider's logo on its sign-in page). Only purposes
 * marked public_variants, only ready files, only the re-encoded variants. Cached
 * for a year: a new logo is a new file id.
 */
final class PublicImageController
{
    public function __invoke(Request $request, string $file, string $variant): Response
    {
        $model = File::query()->where('status', FileStatus::Ready)->find($file);
        $entry = $model?->variants[$variant] ?? null;

        if ($model === null || $entry === null || ! ($model->purposeConfig()['public_variants'] ?? false)) {
            abort(404);
        }

        $etag = '"'.substr((string) $model->sha256, 0, 16).'-'.$variant.'"';
        if ($request->headers->get('If-None-Match') === $etag) {
            return response('', 304, ['ETag' => $etag]);
        }

        $bytes = (string) app(StoreFactory::class)->forFile($model)->disk()->get($entry['path']);

        return response($bytes, 200, [
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'ETag' => $etag,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
        ]);
    }
}
