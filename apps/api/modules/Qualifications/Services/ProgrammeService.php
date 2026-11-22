<?php

declare(strict_types=1);

namespace Modules\Qualifications\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Audit\AuditLogger;
use Modules\Qualifications\Models\CentreQualification;
use Modules\Qualifications\Models\Programme;
use Modules\Qualifications\Models\Qualification;
use Modules\Tenancy\Models\Centre;

/**
 * What a provider offers: qualifications adopted from the catalogue, and which centres
 * are accredited for each. Runs in the provider's context.
 */
final class ProgrammeService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param  array<string, mixed>  $input */
    public function adopt(array $input): Programme
    {
        $data = Validator::make($input, [
            'qualification_id' => ['required', 'ulid'],
            'modes' => ['nullable', 'array'], 'modes.*' => [Rule::in(Programme::MODES)],
            'price_cents' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        // Providers only see published entries (RLS), so a draft or unknown id is "not found".
        $q = Qualification::query()->whereKey($data['qualification_id'])->first()
            ?? throw ValidationException::withMessages(['qualification_id' => 'That qualification is not in the catalogue.']);
        if ($q->status !== 'published') {
            throw ValidationException::withMessages(['qualification_id' => 'That qualification has been withdrawn from the catalogue.']);
        }
        if (in_array($q->enrolmentState(), ['ended'], true)) {
            throw ValidationException::withMessages(['qualification_id' => "{$q->title} has passed its last date for achievement."]);
        }
        if (Programme::query()->where('qualification_id', $q->id)->exists()) {
            throw ValidationException::withMessages(['qualification_id' => 'You already offer this qualification.']);
        }

        $programme = DB::transaction(function () use ($data, $q): Programme {
            $programme = Programme::query()->create($data);
            $this->linkDeclaredScope($q);

            return $programme;
        });
        $this->audit->record('programme.added', $programme, [], ['qualification' => $q->saqa_id ?? $q->curriculum_code]);

        return $programme;
    }

    /** @param  array<string, mixed>  $input */
    public function update(Programme $programme, array $input): Programme
    {
        $data = Validator::make($input, [
            'active' => ['sometimes', 'boolean'],
            'modes' => ['nullable', 'array'], 'modes.*' => [Rule::in(Programme::MODES)],
            'price_cents' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ])->validate();
        $programme->fill($data)->save();

        return $programme;
    }

    /**
     * Replace a centre's scope. Each qualification must be one the provider offers.
     *
     * @param  list<array<string, mixed>>  $items
     */
    public function setCentreScope(Centre $centre, array $items): void
    {
        $rows = Validator::make(['items' => $items], [
            'items' => ['present', 'array', 'max:200'],
            'items.*.qualification_id' => ['required', 'ulid', 'distinct'],
            'items.*.accreditation_number' => ['nullable', 'string', 'max:40'],
            'items.*.expires_on' => ['nullable', 'date'],
        ])->validate()['items'];

        $offered = Programme::query()->pluck('qualification_id')->all();
        foreach ($rows as $i => $row) {
            if (! in_array($row['qualification_id'], $offered, true)) {
                throw ValidationException::withMessages(["items.{$i}.qualification_id" => 'Add this qualification to your programmes first.']);
            }
        }

        DB::transaction(function () use ($centre, $rows): void {
            CentreQualification::query()->where('centre_id', $centre->id)->delete();
            foreach ($rows as $row) {
                CentreQualification::query()->create($row + ['centre_id' => $centre->id]);
            }
        });
        $this->audit->record('centre.scope_updated', $centre, [], ['qualifications' => count($rows)]);
    }

    /** @return array<int, array{qualification_id: string, title: string|null, saqa_id: string|null, accreditation_number: string|null, expires_on: string|null}> */
    public function centreScope(Centre $centre): array
    {
        $links = CentreQualification::query()->where('centre_id', $centre->id)->get();
        $qualifications = Qualification::query()->whereIn('id', $links->pluck('qualification_id'))->get()->keyBy('id');

        return $links->map(fn (CentreQualification $l) => [
            'qualification_id' => $l->qualification_id,
            'title' => $qualifications->get($l->qualification_id)?->title,
            'saqa_id' => $qualifications->get($l->qualification_id)?->saqa_id,
            'accreditation_number' => $l->accreditation_number ?? $centre->accreditation_number,
            'expires_on' => ($l->expires_on ?? $centre->accreditation_expires_on)?->toDateString(),
        ])->values()->all();
    }

    /**
     * SAQA IDs on the provider's accreditation letters (centre scope) that are in the
     * catalogue but not yet offered: a one-click start for new providers.
     *
     * @return Collection<int, Qualification>
     */
    public function suggested(): Collection
    {
        $declared = Centre::query()->pluck('scope')->flatten()->filter()->unique()->values()->all();
        if ($declared === []) {
            return collect();
        }

        return Qualification::query()->where('status', 'published')->whereIn('saqa_id', $declared)
            ->whereNotIn('id', Programme::query()->select('qualification_id'))->orderBy('title')->get();
    }

    /** SAQA IDs declared on centres for a newly offered qualification become centre scope. */
    private function linkDeclaredScope(Qualification $q): void
    {
        if ($q->saqa_id === null) {
            return;
        }

        Centre::query()->whereJsonContains('scope', $q->saqa_id)->get()->each(
            fn (Centre $c) => CentreQualification::query()->firstOrCreate(['centre_id' => $c->id, 'qualification_id' => $q->id]),
        );
    }
}
