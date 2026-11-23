<?php

declare(strict_types=1);

namespace Modules\Enrolment\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Audit\AuditLogger;
use Modules\Enrolment\Models\Application;
use Modules\Enrolment\Models\EnrolmentSettings;
use Modules\Enrolment\Models\Intake;
use Modules\Enrolment\Models\LearnerDocument;
use Modules\Enrolment\Support\DocumentTypes;
use Modules\Enrolment\Support\ImageQuality;
use Modules\Files\Models\File;
use Modules\Files\Storage\StoreFactory;
use Modules\Identity\Models\User;
use Modules\Messaging\Services\MessageService;
use Modules\Tenancy\Context\TenantContext;
use Modules\Tenancy\Models\Tenant;

/**
 * Applicants' documents: checked automatically on arrival (certification age, ID number
 * match, picture quality), then accepted or rejected by staff with a reason.
 */
final class DocumentService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly StoreFactory $stores,
        private readonly MessageService $messages,
        private readonly TenantContext $context,
    ) {}

    /** @param  array<string, mixed>  $input */
    public function add(User $user, array $input): LearnerDocument
    {
        $data = Validator::make($input, [
            'type' => ['required', Rule::in(array_keys(DocumentTypes::ALL))],
            'file_id' => ['required', 'ulid'],
            'certified' => ['sometimes', 'boolean'],
            'certified_on' => ['nullable', 'required_if:certified,true', 'date', 'before_or_equal:today'],
            'certified_by' => ['nullable', 'string', 'max:150'],
            // Read by the app from the barcode on the back of a smart ID card.
            'barcode_id_number' => ['nullable', 'string', 'max:20'],
        ], ['certified_on.required_if' => 'When was the copy certified? It is on the stamp.'])->validate();

        $file = File::query()->whereKey((string) $data['file_id'])->where('owner_id', $user->id)->where('purpose', 'learner_document')->first()
            ?? throw ValidationException::withMessages(['file_id' => 'Upload the document first.']);

        $document = LearnerDocument::query()->create([
            'user_id' => $user->id, 'file_id' => $file->id, 'type' => $data['type'], 'certified' => (bool) ($data['certified'] ?? false),
            'certified_on' => $data['certified_on'] ?? null, 'certified_by' => $data['certified_by'] ?? null,
        ]);
        $document->forceFill(['checks' => $this->checks($document, $user, $file, isset($data['barcode_id_number']) ? (string) $data['barcode_id_number'] : null)])->save();
        $this->audit->record('learner_document.added', $document, [], ['type' => $document->type]);

        return $document;
    }

    public function remove(LearnerDocument $document): void
    {
        if ($document->status === 'accepted') {
            throw ValidationException::withMessages(['document' => 'An accepted document stays on your record. Ask the training provider to change it.']);
        }
        $document->delete();
    }

    public function review(LearnerDocument $document, string $decision, ?string $reason, bool $idConfirmed, User $by): LearnerDocument
    {
        if (! in_array($decision, ['accept', 'reject'], true)) {
            throw ValidationException::withMessages(['decision' => 'Accept or reject.']);
        }
        $checks = $this->recheckAge($document);
        if ($decision === 'reject' && ($reason === null || mb_strlen(trim($reason)) < 5)) {
            throw ValidationException::withMessages(['reason' => 'Tell the applicant what to fix.']);
        }
        if ($decision === 'accept') {
            if (($checks['certification']['ok'] ?? true) === false) {
                throw ValidationException::withMessages(['decision' => $checks['certification']['problem'] ?? 'The certification does not meet your rule.']);
            }
            if ($document->type === 'id_document' && ($checks['id_match'] ?? null) !== true && ! $idConfirmed) {
                throw ValidationException::withMessages(['id_confirmed' => 'Confirm that the number on the document matches the applicant\'s ID number.']);
            }
            if ($document->type === 'id_document' && ($checks['id_match'] ?? null) === false) {
                throw ValidationException::withMessages(['decision' => 'The ID number read from the card does not match the applicant\'s ID number.']);
            }
        }
        $document->forceFill([
            'status' => $decision === 'accept' ? 'accepted' : 'rejected', 'reason' => $decision === 'reject' ? trim((string) $reason) : null,
            'reviewed_by' => $by->id, 'reviewed_at' => now(),
            'checks' => $checks + ($idConfirmed ? ['id_confirmed_by' => $by->id] : []),
        ])->save();
        $this->audit->record('learner_document.'.$document->status, $document, [], ['type' => $document->type, 'reason' => $document->reason]);

        return $document;
    }

    /**
     * Each required document's state for an applicant, from their latest upload of that type.
     *
     * @return array{complete: bool, items: list<array{type: string, label: string, state: string, document_id: string|null, problem: string|null}>}
     */
    public function requirements(Intake $intake, string $userId): array
    {
        $latest = LearnerDocument::query()->where('user_id', $userId)->orderByDesc('created_at')->get()->unique('type')->keyBy('type');
        $items = [];
        foreach ($intake->document_types ?? [] as $type) {
            $doc = $latest->get($type);
            $checks = $doc !== null ? $this->recheckAge($doc) : [];
            $state = match (true) {
                $doc === null => 'missing',
                $doc->status === 'accepted' && ($checks['certification']['ok'] ?? true) => 'accepted',
                $doc->status === 'accepted' => 'expired',
                $doc->status === 'rejected' => 'rejected',
                default => 'pending',
            };
            $items[] = ['type' => $type, 'label' => DocumentTypes::label($type), 'state' => $state, 'document_id' => $doc?->id,
                'problem' => match ($state) {
                    'rejected' => $doc->reason,
                    'expired' => $checks['certification']['problem'] ?? null,
                    'pending' => ($checks['certification']['ok'] ?? true) ? null : ($checks['certification']['problem'] ?? null),
                    default => null,
                }];
        }

        return ['complete' => collect($items)->every(fn ($i) => $i['state'] === 'accepted'), 'items' => $items];
    }

    /**
     * Ask the applicant, by WhatsApp or SMS, for what is missing or was rejected.
     *
     * @return list<array{type: string, label: string, state: string, document_id: string|null, problem: string|null}>
     */
    public function requestMissing(Application $application, ?string $note): array
    {
        $intake = Intake::query()->whereKey($application->intake_id)->firstOrFail();
        $missing = array_values(array_filter($this->requirements($intake, $application->user_id)['items'], fn ($i) => in_array($i['state'], ['missing', 'rejected', 'expired'], true)));
        if ($missing === []) {
            throw ValidationException::withMessages(['documents' => 'Nothing is missing.']);
        }
        $phone = $this->context->runAsPlatform(fn () => User::query()->whereKey($application->user_id)->value('phone_e164'));
        $tenantId = $this->context->tenantId();
        /** @var Tenant $tenant */
        $tenant = $this->context->runAsPlatform(fn () => Tenant::query()->whereKey($tenantId)->firstOrFail());
        $list = implode('; ', array_map(fn ($i) => $i['label'].($i['problem'] ? " ({$i['problem']})" : ''), $missing));
        if (is_string($phone)) {
            $this->messages->notify($phone, "{$tenant->trading_name}: for your application {$application->reference}, please add: {$list}."
                .($note ? " {$note}" : '').' Open the app, or the link you applied on.', 'application');
        }
        $application->forceFill(['documents_requested_at' => now()])->save();
        $this->audit->record('application.documents_requested', $application, [], ['types' => array_column($missing, 'type')]);

        return $missing;
    }

    /** @return array<string, mixed> */
    private function checks(LearnerDocument $document, User $user, File $file, ?string $barcodeId): array
    {
        $checks = $this->recheckAge($document);
        if ($document->type === 'id_document' && $barcodeId !== null) {
            $read = preg_replace('/\D/', '', $barcodeId);
            $checks['id_match'] = $user->id_number !== null && $read === preg_replace('/\D/', '', (string) $user->id_number);
            $checks['id_source'] = 'barcode';
        }
        if (in_array($file->mime, ['image/jpeg', 'image/png'], true)) {
            $bytes = (string) $this->stores->forFile($file)->disk()->get($file->path);
            $checks['quality'] = ImageQuality::check($bytes);
        }

        return $checks;
    }

    /** @return array<string, mixed> the stored checks, with the certification age worked out again for today */
    private function recheckAge(LearnerDocument $document): array
    {
        $checks = $document->checks ?? [];
        $rule = EnrolmentSettings::current()->rule($document->type);
        $problem = null;
        if ($rule['certified'] && ! $document->certified) {
            $problem = 'This must be a certified copy.';
        } elseif ($rule['certified'] && $document->certified_on !== null && $rule['max_age_days'] !== null) {
            $days = (int) CarbonImmutable::parse($document->certified_on)->diffInDays(CarbonImmutable::today());
            if ($days > $rule['max_age_days']) {
                $problem = "Certified {$days} days ago; it must be certified in the last {$rule['max_age_days']} days.";
            }
        }
        $checks['certification'] = ['ok' => $problem === null, 'problem' => $problem];

        return $checks;
    }
}
