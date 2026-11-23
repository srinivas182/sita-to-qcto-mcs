<?php

declare(strict_types=1);

namespace Modules\Agreements\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Tenancy\Concerns\BelongsToTenant;

/**
 * An agreement sent for signing. Its body is frozen when sent and every signature is
 * made against the SHA-256 of that text.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $template_id
 * @property string $kind
 * @property string $title
 * @property string $body
 * @property string $body_sha256
 * @property array<string, string> $merge
 * @property string $signature_level
 * @property string $status
 * @property string|null $provider_ref
 * @property string|null $employer_id
 * @property string|null $site_id
 * @property string|null $qualification_id
 * @property string|null $learner_user_id
 * @property string $verify_code
 * @property string|null $sealed_file_id
 * @property string|null $sealed_sha256
 * @property Carbon|null $sent_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $expires_at
 * @property string|null $created_by
 * @property Carbon $created_at
 */
final class Agreement extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $guarded = ['id', 'status', 'sealed_file_id', 'sealed_sha256', 'completed_at', 'provider_ref'];

    protected function casts(): array
    {
        return ['merge' => 'array', 'sent_at' => 'datetime', 'completed_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    /** @return HasMany<Signer, $this> */
    public function signers(): HasMany
    {
        return $this->hasMany(Signer::class)->orderBy('sort');
    }
}
