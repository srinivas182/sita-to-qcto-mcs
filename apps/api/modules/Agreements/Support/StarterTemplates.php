<?php

declare(strict_types=1);

namespace Modules\Agreements\Support;

/**
 * Standard wording a provider can start from. It is a starting point: providers should
 * check it against their SETA's requirements and their own legal advice before use.
 */
final class StarterTemplates
{
    /** @return array<string, array{kind: string, name: string, parties: list<string>, body: string}> */
    public static function all(): array
    {
        return [
            'workplace' => [
                'kind' => 'workplace',
                'name' => 'Workplace learning agreement',
                'parties' => ['learner', 'employer', 'provider'],
                'body' => <<<'TXT'
# Workplace learning agreement

This agreement is between {{learner.name}} (the learner), {{employer.legal_name}} ({{employer.registration_number}}) (the host employer) and {{provider.legal_name}} (the training provider), for the work experience of the {{qualification.title}} (SAQA {{qualification.saqa_id}}).

## 1. Purpose and period

1.1 The learner will complete the work experience modules of the qualification at {{site.name}}, {{site.address}} (workplace approval {{site.approval_number}}).

1.2 The work experience runs from {{start_date}} to {{end_date}}, unless ended earlier under clause 6.

## 2. The training provider will

2.1 Deliver the knowledge and practical modules and prepare the learner for the workplace.

2.2 Give the learner and the mentor the logbook and explain how evidence is recorded.

2.3 Visit or contact the workplace during the period to monitor progress, and help resolve problems.

2.4 Keep the learner's records and report to the Quality Council for Trades and Occupations and the SETA as required.

## 3. The host employer will

3.1 Provide a safe workplace that meets the Occupational Health and Safety Act, with the induction, protective equipment and supervision the work needs.

3.2 Appoint {{mentor.name}}, or another suitably experienced person, as workplace mentor, and give them time to guide the learner and sign the logbook.

3.3 Give the learner work that covers the work experience modules, and not use the learner to replace an employee.

3.4 Tell the training provider promptly about absence, injury, misconduct or anything that puts the placement at risk.

## 4. The learner will

4.1 Attend as agreed, follow the employer's workplace rules and safety instructions, and work under the mentor's guidance.

4.2 Keep the logbook up to date and give the mentor the evidence to sign.

4.3 Tell the mentor and the training provider about any absence or problem.

## 5. Personal information

5.1 Each party will use the others' personal information only for this placement, the qualification and the reports the law or the SETA require, and will protect it as the Protection of Personal Information Act requires.

## 6. Ending the agreement

6.1 Any party may end this agreement by giving the others written notice, with reasons. The training provider will try to resolve problems first.

6.2 A placement ends automatically if the workplace approval ends and is not renewed.

## 7. Disputes

7.1 The parties will first try to resolve a dispute between themselves. If they cannot, any party may refer it to the SETA.

## 8. Signature

8.1 The parties sign electronically. Each signature is confirmed by a one-time PIN sent to the signer's phone or email, and the signed document carries a verification page.
TXT,
            ],
            'employer_mou' => [
                'kind' => 'employer_mou',
                'name' => 'Host employer memorandum of understanding',
                'parties' => ['employer', 'provider'],
                'body' => <<<'TXT'
# Memorandum of understanding

Between {{employer.legal_name}} ({{employer.registration_number}}, levy number {{employer.sdl_number}}) (the host employer) and {{provider.legal_name}} (the training provider).

## 1. Purpose

1.1 The host employer agrees to host learners of the training provider for work experience at its approved workplaces, starting with {{site.name}} (approval {{site.approval_number}}).

## 2. Commitments

2.1 The host employer keeps its workplace approvals in date, provides mentors in line with the SETA's mentor-to-learner ratio, and tells the training provider when a mentor leaves.

2.2 The training provider places only learners who are ready for the workplace, supports mentors with the logbooks, and shares the reports the employer needs for its grant claims.

## 3. Learners

3.1 Each learner placed signs a separate workplace learning agreement with both parties.

## 4. Period

4.1 This memorandum runs from {{start_date}} to {{end_date}} and may be ended by either party with 30 days' written notice. Learners already placed finish their agreed period where possible.
TXT,
            ],
            'learner' => [
                'kind' => 'learner',
                'name' => 'Learner enrolment agreement',
                'parties' => ['learner', 'provider'],
                'body' => <<<'TXT'
# Learner enrolment agreement

Between {{learner.name}} (the learner) and {{provider.legal_name}} (the training provider), for the {{qualification.title}} (SAQA {{qualification.saqa_id}}), from {{start_date}} to {{end_date}}.

## 1. The training provider will

1.1 Deliver the qualification as accredited, assess the learner fairly, and give the learner the results and the right to appeal.

## 2. The learner will

2.1 Attend, complete the assignments and assessments, and follow the provider's rules.

## 3. Personal information

3.1 The learner agrees that the provider may share their information with the QCTO, the SETA and the assessment body as needed to register, assess and certificate them.
TXT,
            ],
        ];
    }
}
