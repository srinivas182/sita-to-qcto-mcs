<?php

declare(strict_types=1);

namespace Modules\Enrolment\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Tenancy\Concerns\BelongsToTenant;

/**
 * @property string $id
 * @property string $purpose
 * @property int $version
 * @property string $text
 * @property Carbon|null $updated_at
 */
final class ConsentText extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public const DEFAULT_ENROLMENT = <<<'TXT'
I agree that {provider} may use my personal information (name, ID number, contact details, documents, attendance, assessment results and, only if I choose to give it, my gender, race and disability status) to enrol me, deliver and assess my training, and register me and report my progress to the Quality Council for Trades and Occupations (QCTO), the SETA and the assessment body, as the law requires.

{provider} keeps my information safe, uses it only for these purposes, and keeps it only as long as the law requires. I may see and correct my information, and I may withdraw this consent; withdrawing it may mean I can no longer be enrolled or certificated.
TXT;

    protected $fillable = ['purpose', 'version', 'text', 'created_by'];

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }

    /** The latest wording for a purpose, creating version 1 from the default the first time. */
    public static function current(string $purpose = 'enrolment'): self
    {
        return self::query()->where('purpose', $purpose)->orderByDesc('version')->first()
            ?? self::query()->create(['purpose' => $purpose, 'version' => 1, 'text' => self::DEFAULT_ENROLMENT]);
    }
}
