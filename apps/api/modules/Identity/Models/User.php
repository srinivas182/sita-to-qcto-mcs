<?php

declare(strict_types=1);

namespace Modules\Identity\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use Modules\Audit\Concerns\Auditable;
use Modules\Identity\Database\Factories\UserFactory;
use Modules\Identity\Support\IdentityHasher;
use Modules\Identity\Support\IdType;
use Modules\Identity\Support\UserStatus;

/**
 * A person. Global across training providers; a provider sees a person only while
 * they are a member (row-level security on users).
 *
 * @property string $id
 * @property string $first_name
 * @property string $last_name
 * @property string|null $email
 * @property string|null $phone_e164
 * @property IdType|null $id_type
 * @property string|null $id_country
 * @property string|null $id_number_hash
 * @property string|null $id_number
 * @property Carbon|null $dob
 * @property string $locale
 * @property UserStatus $status
 * @property string|null $password
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $phone_verified_at
 * @property Carbon|null $last_login_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class User extends Authenticatable
{
    use Auditable;

    /** @use HasApiTokens<PersonalAccessToken> */
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasUlids;
    use Notifiable;

    protected $fillable = [
        'first_name', 'last_name', 'email', 'phone_e164', 'dob', 'locale', 'status', 'password',
    ];

    protected $hidden = ['password', 'remember_token', 'id_number', 'id_number_hash'];

    /** Mirror database defaults so new, unsaved-then-saved models behave the same. */
    protected $attributes = ['status' => 'active', 'locale' => 'en'];

    protected function casts(): array
    {
        return [
            'id_type' => IdType::class,
            'id_number' => 'encrypted',
            'dob' => 'date',
            'status' => UserStatus::class,
            'password' => 'hashed',
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    /** Store an identity document: lookup hash plus encrypted value. */
    public function setIdentityNumber(IdType $type, string $number, ?string $country = null): void
    {
        $normalised = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $number));

        $this->id_type = $type;
        $this->id_country = $type === IdType::SaId ? 'ZA' : ($country !== null ? strtoupper($country) : null);
        $this->id_number = $normalised;
        $this->id_number_hash = IdentityHasher::identityNumber($type, $normalised, $type === IdType::SaId ? null : $this->id_country);
    }

    /** "8001015009087" → "800101•••••87" for screens; never log the full number. */
    public function maskedIdNumber(): ?string
    {
        $value = $this->id_number;

        return $value === null ? null : substr($value, 0, 6).str_repeat('•', max(0, strlen($value) - 8)).substr($value, -2);
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    /** @return HasMany<UserTenant, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(UserTenant::class);
    }

    /** @return HasMany<Passkey, $this> */
    public function passkeys(): HasMany
    {
        return $this->hasMany(Passkey::class);
    }

    /** @return HasMany<MfaFactor, $this> */
    public function mfaFactors(): HasMany
    {
        return $this->hasMany(MfaFactor::class);
    }

    /** @return HasMany<Device, $this> */
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    /** @return HasMany<Consent, $this> */
    public function consents(): HasMany
    {
        return $this->hasMany(Consent::class);
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }
}
