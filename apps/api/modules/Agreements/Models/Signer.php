<?php

declare(strict_types=1);

namespace Modules\Agreements\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Tenancy\Concerns\BelongsToTenant;

/**
 * One party's signature: who, how their identity was confirmed, and when.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $agreement_id
 * @property string $role
 * @property int $sort
 * @property string $name
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $user_id
 * @property string $token_hash
 * @property string $status
 * @property string|null $code_hash
 * @property Carbon|null $code_expires_at
 * @property Carbon|null $code_sent_at
 * @property int $code_attempts
 * @property int $codes_sent
 * @property int $reminders_sent
 * @property Carbon|null $signed_at
 * @property string|null $signed_name
 * @property string|null $ip
 * @property string|null $user_agent
 * @property array<string, mixed>|null $evidence
 * @property string|null $decline_reason
 */
final class Signer extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'agreement_signers';

    protected $fillable = ['agreement_id', 'role', 'sort', 'name', 'email', 'phone', 'user_id', 'token_hash'];

    protected $hidden = ['token_hash', 'code_hash'];

    protected function casts(): array
    {
        return ['evidence' => 'array', 'signed_at' => 'datetime', 'code_expires_at' => 'datetime', 'code_sent_at' => 'datetime'];
    }

    /** @return BelongsTo<Agreement, $this> */
    public function agreement(): BelongsTo
    {
        return $this->belongsTo(Agreement::class);
    }

    /** +27 82 ••• 4567 style, for showing where a PIN went. */
    public function maskedPhone(): ?string
    {
        return $this->phone === null ? null : substr($this->phone, 0, 3).' '.substr($this->phone, 3, 2).' ••• '.substr($this->phone, -4);
    }

    public function maskedEmail(): ?string
    {
        if ($this->email === null || ! str_contains($this->email, '@')) {
            return null;
        }
        [$user, $domain] = explode('@', $this->email, 2);

        return mb_substr($user, 0, 1).'•••@'.$domain;
    }
}
