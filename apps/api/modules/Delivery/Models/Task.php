<?php

declare(strict_types=1);

namespace Modules\Delivery\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Modules\Tenancy\Concerns\BelongsToTenant;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $qualification_id
 * @property string|null $module_id
 * @property string $title
 * @property string|null $instructions
 * @property list<array{id: string, type: string, text: string, options?: list<string>, correct?: list<int>, marks: int}> $questions
 * @property bool $evidence
 * @property string|null $evidence_hint
 * @property int $pass_pct
 * @property int $attempts
 * @property string $status
 * @property string|null $created_by
 */
final class Task extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $attributes = ['status' => 'draft', 'pass_pct' => 70, 'attempts' => 3, 'evidence' => false];

    protected $fillable = ['qualification_id', 'module_id', 'title', 'instructions', 'questions', 'evidence', 'evidence_hint', 'pass_pct', 'attempts', 'created_by'];

    protected function casts(): array
    {
        return ['questions' => 'array', 'evidence' => 'boolean', 'pass_pct' => 'integer', 'attempts' => 'integer'];
    }

    /** Marks available: questions plus 10 for evidence. */
    public function outOf(): int
    {
        return array_sum(array_map(fn (array $q) => (int) $q['marks'], $this->questions)) + ($this->evidence ? 10 : 0);
    }

    /** Needs a person to mark: short answers or evidence. */
    public function needsMarking(): bool
    {
        return $this->evidence || collect($this->questions)->contains('type', 'short');
    }
}
