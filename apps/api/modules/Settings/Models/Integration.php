<?php

declare(strict_types=1);

namespace Modules\Settings\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Audit\Concerns\Auditable;

/**
 * An external service the Super Admin has set up (a payment gateway, SMS provider,
 * WhatsApp number, mail service or file store).
 *
 * @property string $id
 * @property string $type
 * @property string $driver
 * @property array<string, mixed>|null $credentials secret values, encrypted at rest
 * @property array<string, mixed>|null $settings non-secret values
 * @property string $mode
 * @property bool $enabled
 * @property int $priority
 * @property Carbon|null $last_tested_at
 * @property bool|null $last_test_ok
 * @property string|null $last_test_message
 * @property string|null $updated_by
 * @property Carbon $updated_at
 */
final class Integration extends Model
{
    use Auditable, HasUlids;

    protected $fillable = [
        'type', 'driver', 'credentials', 'settings', 'mode', 'enabled', 'priority',
        'last_tested_at', 'last_test_ok', 'last_test_message', 'updated_by',
    ];

    /** Hidden attributes are also redacted in the audit log. */
    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'settings' => 'array',
            'enabled' => 'boolean',
            'priority' => 'integer',
            'last_tested_at' => 'datetime',
            'last_test_ok' => 'boolean',
        ];
    }

    /** All configured values, secret and not, for the driver to use. */
    public function value(string $field, mixed $default = null): mixed
    {
        return $this->credentials[$field] ?? $this->settings[$field] ?? $default;
    }

    /** @return array<string, mixed> */
    public function values(): array
    {
        return [...($this->settings ?? []), ...($this->credentials ?? [])];
    }

    public function isSandbox(): bool
    {
        return $this->mode === 'sandbox';
    }

    /**
     * Enabled drivers of a type, in the order the Super Admin chose.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query, string $type): Builder
    {
        return $query->where('type', $type)->where('enabled', true)->orderBy('priority')->orderBy('created_at');
    }
}
