<?php

declare(strict_types=1);

namespace Modules\Agreements\Events;

/** Everyone has signed. Other modules act on it (e.g. enrolment completes when a learner agreement is signed). */
final class AgreementCompleted
{
    public function __construct(public readonly string $agreementId, public readonly string $tenantId) {}
}
