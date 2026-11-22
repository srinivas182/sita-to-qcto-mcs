<?php

declare(strict_types=1);

namespace Modules\Onboarding\Notifications;

use Modules\Onboarding\Models\Application;

/**
 * What application emails need, copied at send time. Queued mail must not re-load the
 * model: workers run without the platform context, so row-level security hides it.
 */
final readonly class ApplicationSummary
{
    public function __construct(
        public string $id,
        public string $reference,
        public string $first_name,
        public string $legal_name,
        public ?string $decision_reason,
    ) {}

    public static function of(Application $application): self
    {
        return new self($application->id, $application->reference, $application->first_name, $application->legal_name, $application->decision_reason);
    }
}
