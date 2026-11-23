<?php

declare(strict_types=1);

namespace Modules\Funding\Services;

use Modules\Assessment\Models\EisaRegistration;
use Modules\Enrolment\Models\Enrolment;
use Modules\Enrolment\Services\QctoEnrolmentList;
use Modules\Funding\Models\BankAccount;
use Modules\Funding\Models\GrantAgreement;
use Modules\Funding\Models\GrantLearner;
use Modules\Identity\Models\User;

/** What a learner can fix themselves in one tap: bank details, the EISA declaration, profile problems. */
final class LearnerTodo
{
    /** @return array{bank_missing: bool, eisa_to_sign: int, profile_problems: int} */
    public function for(User $u): array
    {
        $enrolments = Enrolment::query()->where('user_id', $u->id)->whereIn('status', ['active', 'suspended'])->pluck('id');
        $agreements = GrantLearner::query()->whereIn('enrolment_id', $enrolments)->where('status', 'active')->pluck('agreement_id');
        $paysStipend = GrantAgreement::query()->whereIn('id', $agreements)->whereNotNull('stipend_cents')->where('stipend_cents', '>', 0)->exists();
        $problems = 0;
        foreach (app(QctoEnrolmentList::class)->forUser($u) as $c) {
            $problems += count($c['errors']);
        }

        return [
            'bank_missing' => $paysStipend && ! BankAccount::query()->where('user_id', $u->id)->exists(),
            'eisa_to_sign' => EisaRegistration::query()->where('user_id', $u->id)->where('status', 'draft')->whereNull('declared_at')->count(),
            'profile_problems' => $problems,
        ];
    }
}
