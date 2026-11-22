<?php

declare(strict_types=1);

namespace Modules\Files\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Files\Support\FileStatus;
use Modules\Tenancy\Concerns\BelongsToTenant;

/**
 * An uploaded file. The bytes live in the store named by `store_driver` and
 * `integration_id`; nothing is usable until scanning has marked it ready.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string|null $owner_id
 * @property string $purpose
 * @property string $original_name
 * @property string $mime
 * @property int $size
 * @property string|null $sha256
 * @property string $store_driver
 * @property string|null $integration_id
 * @property string $path
 * @property FileStatus $status
 * @property string|null $status_reason
 * @property array<string, array{path: string, width: int, height: int, size: int}>|null $variants
 * @property Carbon|null $uploaded_at
 * @property Carbon|null $scanned_at
 * @property Carbon $created_at
 */
final class File extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = [
        'tenant_id', 'owner_id', 'purpose', 'original_name', 'mime', 'size', 'sha256',
        'store_driver', 'integration_id', 'path', 'status', 'status_reason', 'variants',
        'uploaded_at', 'scanned_at',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'status' => FileStatus::class,
            'variants' => 'array',
            'uploaded_at' => 'datetime',
            'scanned_at' => 'datetime',
        ];
    }

    public function isReady(): bool
    {
        return $this->status === FileStatus::Ready;
    }

    /** @return array<string, mixed> */
    public function purposeConfig(): array
    {
        /** @var array<string, mixed> */
        return (array) config("files.purposes.{$this->purpose}", []);
    }
}
