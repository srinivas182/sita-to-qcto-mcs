<?php

declare(strict_types=1);

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Modules\Audit\Concerns\Auditable;

/**
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property list<string>|null $features
 * @property int $base_fee_cents
 * @property int $per_learner_cents
 * @property int $included_learners
 * @property int|null $max_learners
 * @property int|null $max_centres
 * @property int $annual_discount_percent
 * @property int $trial_days
 * @property bool $is_example
 * @property bool $active
 * @property bool $public
 * @property int $sort
 */
final class Package extends Model
{
    use Auditable, HasUlids;

    /** @var array<string, mixed> */
    protected $attributes = [
        'per_learner_cents' => 0, 'included_learners' => 0, 'annual_discount_percent' => 0, 'trial_days' => 14,
        'is_example' => false, 'active' => true, 'public' => true, 'sort' => 100,
    ];

    protected $fillable = [
        'code', 'name', 'description', 'features', 'base_fee_cents', 'per_learner_cents', 'included_learners',
        'max_learners', 'max_centres', 'annual_discount_percent', 'trial_days', 'is_example', 'active', 'public', 'sort',
    ];

    protected function casts(): array
    {
        return [
            'features' => 'array',
            'base_fee_cents' => 'integer',
            'per_learner_cents' => 'integer',
            'included_learners' => 'integer',
            'max_learners' => 'integer',
            'max_centres' => 'integer',
            'annual_discount_percent' => 'integer',
            'trial_days' => 'integer',
            'is_example' => 'boolean',
            'active' => 'boolean',
            'public' => 'boolean',
            'sort' => 'integer',
        ];
    }

    /** Base fee for one billing period (a year is 12 months less the annual discount). */
    public function baseFeeFor(string $interval): int
    {
        if ($interval === 'year') {
            return (int) round($this->base_fee_cents * 12 * (100 - $this->annual_discount_percent) / 100);
        }

        return $this->base_fee_cents;
    }

    /** @return array<string, mixed> */
    public function present(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'features' => $this->features ?? [],
            'base_fee_cents' => $this->base_fee_cents,
            'annual_fee_cents' => $this->baseFeeFor('year'),
            'per_learner_cents' => $this->per_learner_cents,
            'included_learners' => $this->included_learners,
            'max_learners' => $this->max_learners,
            'max_centres' => $this->max_centres,
            'annual_discount_percent' => $this->annual_discount_percent,
            'trial_days' => $this->trial_days,
            'is_example' => $this->is_example,
            'active' => $this->active,
            'public' => $this->public,
            'sort' => $this->sort,
        ];
    }
}
