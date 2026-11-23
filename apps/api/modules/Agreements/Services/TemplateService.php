<?php

declare(strict_types=1);

namespace Modules\Agreements\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Agreements\Models\AgreementSettings;
use Modules\Agreements\Models\Template;
use Modules\Agreements\Support\AgreementText;
use Modules\Agreements\Support\StarterTemplates;
use Modules\Audit\AuditLogger;
use Modules\Identity\Models\User;

/** Agreement wording with versions: drafts change; published versions never do. */
final class TemplateService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function fromStarter(string $key): Template
    {
        $starter = StarterTemplates::all()[$key] ?? throw ValidationException::withMessages(['starter' => 'Choose one of the standard agreements.']);

        return $this->save($starter, null);
    }

    /** @param  array<string, mixed>  $input */
    public function save(array $input, ?Template $existing): Template
    {
        $req = $existing ? 'sometimes' : 'required';
        $data = Validator::make($input, [
            'kind' => [$req, Rule::in(Template::KINDS)],
            'name' => [$req, 'string', 'min:3', 'max:150'],
            'body' => [$req, 'string', 'min:50', 'max:60000'],
            'parties' => [$req, 'array', 'min:2', 'max:3'],
            'parties.*' => ['distinct', Rule::in(Template::ROLES)],
            'advanced_signature' => ['sometimes', 'boolean'],
        ], ['parties.min' => 'At least two parties sign.'])->validate();

        if (isset($data['body']) && ($unknown = AgreementText::unknown($data['body'])) !== []) {
            throw ValidationException::withMessages(['body' => 'Unknown fields: {{'.implode('}}, {{', $unknown).'}}.']);
        }
        if (isset($data['parties']) && ! in_array('provider', $data['parties'], true)) {
            throw ValidationException::withMessages(['parties' => 'The training provider is always a party.']);
        }

        // Changing a published version starts the next draft.
        if ($existing !== null && $existing->status !== 'draft') {
            $draft = Template::query()->where('family_id', $existing->family_id)->where('status', 'draft')->first();
            $existing = $draft ?? new Template([
                'family_id' => $existing->family_id, 'kind' => $existing->kind, 'name' => $existing->name, 'body' => $existing->body,
                'parties' => $existing->parties, 'advanced_signature' => $existing->advanced_signature,
                'version' => (int) Template::query()->where('family_id', $existing->family_id)->max('version') + 1,
            ]);
        }
        $template = $existing ?? new Template([
            'family_id' => (string) Str::ulid(), 'version' => 1,
            'advanced_signature' => AgreementSettings::current()->advanced_by_default,
        ]);
        $template->fill($data);
        if (! $template->exists) {
            $template->forceFill(['status' => 'draft']);
        }
        $template->save();
        $this->audit->record($template->wasRecentlyCreated ? 'agreement_template.drafted' : 'agreement_template.updated', $template, [], ['name' => $template->name, 'version' => $template->version]);

        return $template;
    }

    public function publish(Template $template, User $by): Template
    {
        if ($template->status !== 'draft') {
            throw ValidationException::withMessages(['status' => 'Only a draft can be published.']);
        }
        DB::transaction(function () use ($template, $by): void {
            Template::query()->where('family_id', $template->family_id)->where('status', 'published')->update(['status' => 'retired']);
            $template->forceFill(['status' => 'published', 'published_at' => now(), 'published_by' => $by->id])->save();
        });
        $this->audit->record('agreement_template.published', $template, [], ['name' => $template->name, 'version' => $template->version]);

        return $template;
    }

    public function retire(Template $template): void
    {
        $template->forceFill(['status' => 'retired'])->save();
        $this->audit->record('agreement_template.retired', $template, [], ['name' => $template->name, 'version' => $template->version]);
    }
}
