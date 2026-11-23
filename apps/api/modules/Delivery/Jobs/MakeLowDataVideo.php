<?php

declare(strict_types=1);

namespace Modules\Delivery\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Modules\Delivery\Models\Material;
use Modules\Files\Models\File;
use Modules\Files\Storage\StoreFactory;
use Modules\Files\Support\FileStatus;
use Throwable;

/**
 * A low-data copy of a course video (360p H.264, about a quarter of the size), so learners
 * on prepaid data can watch or download it. Runs in the tenant context it was dispatched
 * from. Without ffmpeg on the worker the material simply has no low-data copy.
 */
final class MakeLowDataVideo implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 2;

    public int $timeout = 1800;

    public function __construct(public readonly string $materialId) {}

    public function handle(StoreFactory $stores): void
    {
        $m = Material::query()->find($this->materialId);
        $source = $m?->file_id ? File::query()->find($m->file_id) : null;
        if ($m === null || $source === null || $m->kind !== 'video') {
            return;
        }
        $ffmpeg = (string) config('delivery.ffmpeg', 'ffmpeg');
        if (! Process::run([$ffmpeg, '-version'])->successful()) {
            $m->forceFill(['low_status' => 'none'])->save();

            return;
        }
        $in = tempnam(sys_get_temp_dir(), 'vin');
        $out = tempnam(sys_get_temp_dir(), 'vout').'.mp4';
        try {
            $stream = $stores->forFile($source)->disk()->readStream($source->path);
            $fh = fopen((string) $in, 'wb');
            if ($stream === null || $fh === false) {
                throw new \RuntimeException('The video could not be read.');
            }
            stream_copy_to_stream($stream, $fh);
            fclose($fh);
            fclose($stream);
            $run = Process::timeout(1700)->run([$ffmpeg, '-y', '-i', (string) $in, '-vf', 'scale=-2:min(360\,ih)', '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '30',
                '-c:a', 'aac', '-b:a', '64k', '-ac', '1', '-movflags', '+faststart', $out]);
            if (! $run->successful() || ! is_file($out)) {
                throw new \RuntimeException('ffmpeg failed: '.Str::limit($run->errorOutput(), 300));
            }
            $duration = null;
            if (preg_match('/Duration: (\d+):(\d+):(\d+)/', $run->errorOutput(), $d)) {
                $duration = (int) $d[1] * 3600 + (int) $d[2] * 60 + (int) $d[3];
            }
            [$store, $integration] = $stores->current();
            $id = strtolower((string) Str::ulid());
            $path = sprintf('%s/learning_material/%s/%s-360p.mp4', $m->tenant_id, now()->format('Y/m'), $id);
            $handle = fopen($out, 'rb');
            if ($handle === false) {
                throw new \RuntimeException('The low-data copy could not be read.');
            }
            $store->disk()->writeStream($path, $handle);
            fclose($handle);
            $size = (int) filesize($out);
            $low = File::query()->create(['id' => $id, 'tenant_id' => $m->tenant_id, 'owner_id' => $source->owner_id, 'purpose' => 'learning_material',
                'original_name' => pathinfo($source->original_name, PATHINFO_FILENAME).'-360p.mp4', 'mime' => 'video/mp4', 'size' => $size,
                'sha256' => hash_file('sha256', $out), 'store_driver' => $store->driver(), 'integration_id' => $integration?->id, 'path' => $path,
                'status' => FileStatus::Ready, 'uploaded_at' => now(), 'scanned_at' => now()]);
            $m->forceFill(['low_file_id' => $low->id, 'low_status' => 'ready', 'duration_s' => $duration])->save();
        } catch (Throwable $e) {
            report($e);
            $m->forceFill(['low_status' => 'failed'])->save();
        } finally {
            @unlink((string) $in);
            @unlink($out);
        }
    }
}
