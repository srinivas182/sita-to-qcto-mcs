<?php

declare(strict_types=1);

namespace Modules\Onboarding\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $application_id
 * @property string $file_id
 * @property string $kind
 */
final class ApplicationFile extends Model
{
    use HasUlids;

    protected $fillable = ['application_id', 'file_id', 'kind'];
}
