<?php

declare(strict_types=1);

namespace Modules\People\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Audit\AuditLogger;
use Modules\Identity\Models\User;
use Modules\Identity\Support\IdentityHasher;
use Modules\Identity\Support\IdType;
use Modules\Identity\Support\PhoneNumber;
use Modules\Identity\Support\SaIdNumber;
use Modules\People\Models\PersonProfile;
use Modules\Tenancy\Context\TenantContext;

/**
 * A person's profile with this provider, their identity document, and optional
 * equity data (only with explicit consent; withdrawing consent erases it).
 */
final class ProfileService
{
    public const GENDERS = ['female', 'male', 'non_binary', 'prefer_not_to_say'];

    /** The race groups SETA reports use (Stats SA). */
    public const RACES = ['african', 'coloured', 'indian', 'white', 'other', 'prefer_not_to_say'];

    public const PROVINCES = ['Eastern Cape', 'Free State', 'Gauteng', 'KwaZulu-Natal', 'Limpopo', 'Mpumalanga', 'North West', 'Northern Cape', 'Western Cape'];

    public function __construct(private readonly AuditLogger $audit, private readonly TenantContext $context) {}

    /** @return array<string, mixed> */
    public function present(User $person, bool $withEquity): array
    {
        $profile = PersonProfile::query()->where('user_id', $person->id)->first();
        $consented = $profile?->equity_consented_at !== null;

        return [
            'id' => $person->id,
            'first_name' => $person->first_name,
            'last_name' => $person->last_name,
            'email' => $person->email,
            'phone' => $person->phone_e164,
            'id_type' => $person->id_type?->value,
            'id_number' => $person->maskedIdNumber(),
            'id_country' => $person->id_country,
            'dob' => $person->dob?->toDateString(),
            'address_line1' => $profile?->address_line1,
            'address_line2' => $profile?->address_line2,
            'city' => $profile?->city,
            'province' => $profile?->province,
            'postal_code' => $profile?->postal_code,
            'home_language' => $profile?->home_language,
            'emergency_name' => $profile?->emergency_name,
            'emergency_phone' => $profile?->emergency_phone,
            'emergency_relationship' => $profile?->emergency_relationship,
            'equity_consented' => $consented,
            'equity' => $withEquity && $consented ? ($profile->equity ?? []) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{warnings: list<string>}
     */
    public function update(User $person, array $input, User $actor, bool $mayChangeIdentity): array
    {
        $data = Validator::make($input, [
            'first_name' => ['sometimes', 'string', 'min:1', 'max:100'],
            'last_name' => ['sometimes', 'string', 'min:1', 'max:100'],
            'id_type' => ['sometimes', Rule::in([IdType::SaId->value, IdType::Passport->value])],
            'id_number' => ['required_with:id_type', 'nullable', 'string', 'max:30'],
            'id_country' => ['required_if:id_type,passport', 'nullable', 'string', 'size:2'],
            'dob' => ['nullable', 'date', 'before:today'],
            'address_line1' => ['nullable', 'string', 'max:200'],
            'address_line2' => ['nullable', 'string', 'max:200'],
            'city' => ['nullable', 'string', 'max:100'],
            'province' => ['nullable', Rule::in(self::PROVINCES)],
            'postal_code' => ['nullable', 'regex:/^\d{4}$/'],
            'home_language' => ['nullable', 'string', 'max:40'],
            'emergency_name' => ['nullable', 'string', 'max:150'],
            'emergency_phone' => ['nullable', 'string', 'max:20'],
            'emergency_relationship' => ['nullable', 'string', 'max:60'],
            'equity_consent' => ['sometimes', 'boolean'],
            'equity' => ['nullable', 'array'],
            'equity.gender' => ['nullable', Rule::in(self::GENDERS)],
            'equity.race' => ['nullable', Rule::in(self::RACES)],
            'equity.disability' => ['nullable', 'boolean'],
            'equity.disability_details' => ['nullable', 'string', 'max:300'],
        ], ['postal_code.regex' => 'A South African postal code has 4 digits.'])->validate();

        $warnings = [];

        // Identity document: checked, unique across the platform, kept hashed and encrypted.
        if (isset($data['id_type'])) {
            if ($person->id_number !== null && ! $mayChangeIdentity) {
                throw ValidationException::withMessages(['id_number' => 'Your ID number is already recorded. Ask your training provider to correct it.']);
            }
            $type = IdType::from($data['id_type']);
            $number = (string) $data['id_number'];
            $sa = null;
            if ($type === IdType::SaId) {
                $sa = SaIdNumber::tryParse($number) ?? throw ValidationException::withMessages(['id_number' => 'That is not a valid South African ID number. Check the 13 digits.']);
                $number = $sa->value;
            }
            $hash = IdentityHasher::identityNumber($type, strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $number)), $type === IdType::SaId ? null : strtoupper((string) $data['id_country']));
            $taken = $this->context->runAsPlatform(fn () => User::query()->where('id_number_hash', $hash)->whereKeyNot($person->id)->exists());
            if ($taken) {
                throw ValidationException::withMessages(['id_number' => 'This ID number is already registered to another person. Contact support to resolve it.']);
            }
            $this->context->runAsPlatform(function () use ($person, $type, $number, $data, $sa): void {
                $person->setIdentityNumber($type, $number, $data['id_country'] ?? null);
                if ($sa !== null) {
                    $person->dob = Carbon::instance($sa->dateOfBirth); // the ID number decides the date of birth
                }
                $person->save();
            });
            $this->audit->record('person.identity_set', $person, [], ['type' => $type->value, 'by' => $actor->id]);
        }

        $names = array_intersect_key($data, array_flip(['first_name', 'last_name']));
        if (array_key_exists('dob', $data) && $person->id_type !== IdType::SaId) {
            $names['dob'] = $data['dob'];
        }
        if ($names !== []) {
            $this->context->runAsPlatform(fn () => $person->fill($names)->save());
        }

        $profile = PersonProfile::query()->firstOrNew(['user_id' => $person->id]);
        $fields = array_intersect_key($data, array_flip(['address_line1', 'address_line2', 'city', 'province', 'postal_code', 'home_language',
            'emergency_name', 'emergency_phone', 'emergency_relationship']));
        if (isset($fields['emergency_phone'])) {
            $fields['emergency_phone'] = PhoneNumber::normalise((string) $fields['emergency_phone'])
                ?? throw ValidationException::withMessages(['emergency_phone' => 'That mobile number is not valid.']);
        }
        $profile->fill($fields);

        if (($data['equity_consent'] ?? null) === false) {
            // Withdrawn: the data goes with it.
            $profile->forceFill(['equity' => null, 'equity_consented_at' => null, 'equity_consent_version' => null]);
            $this->audit->record('person.equity_consent_withdrawn', $person, [], ['by' => $actor->id]);
        } elseif (($data['equity_consent'] ?? null) === true || ($profile->equity_consented_at !== null && isset($data['equity']))) {
            if ($profile->equity_consented_at === null) {
                $profile->forceFill(['equity_consented_at' => now(), 'equity_consent_version' => PersonProfile::EQUITY_CONSENT_VERSION]);
                $this->audit->record('person.equity_consent_given', $person, [], ['by' => $actor->id, 'version' => PersonProfile::EQUITY_CONSENT_VERSION]);
            }
            if (isset($data['equity'])) {
                $profile->equity = array_filter($data['equity'], fn ($v) => $v !== null && $v !== '') + ($profile->equity ?? []);
            }
        } elseif (isset($data['equity'])) {
            throw ValidationException::withMessages(['equity_consent' => 'Equity information is only kept with the person\'s consent.']);
        }
        $profile->save();

        // A gender different from the one the SA ID was issued with is allowed; we only point it out.
        $gender = $profile->equity['gender'] ?? null;
        if (in_array($gender, ['female', 'male'], true) && $person->id_type === IdType::SaId && $person->id_number !== null) {
            $sa = SaIdNumber::tryParse($person->id_number);
            if ($sa !== null && $sa->sexOnIssue() !== $gender) {
                $warnings[] = 'The gender differs from the one on the ID number. That is fine if it is correct; SETA reports use what is recorded here.';
            }
        }

        return ['warnings' => $warnings];
    }
}
