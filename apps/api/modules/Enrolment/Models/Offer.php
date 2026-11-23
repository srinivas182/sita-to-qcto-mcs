<?php

declare(strict_types=1);

namespace Modules\Enrolment\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Tenancy\Concerns\BelongsToTenant;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $application_id
 * @property string $token_hash
 * @property string $status
 * @property string|null $funding
 * @property Carbon $expires_at
 * @property array{name: string, phone: string, relationship: string}|null $guardian
 * @property string|null $agreement_id
 * @property Carbon|null $responded_at
 * @property string|null $reason
 * @property string|null $created_by
 */
final class Offer extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public const FUNDING = ['seta_grant', 'employer', 'self', 'bursary', 'government', 'other'];

    protected $attributes = ['status' => 'open'];

    protected $fillable = ['application_id', 'token_hash', 'funding', 'expires_at', 'created_by'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'responded_at' => 'datetime', 'guardian' => 'array'];
    }

    public function isOpen(): bool
    {
        return $this->status === 'open' && $this->expires_at->isFuture();
    }
}
