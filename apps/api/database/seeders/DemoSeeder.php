<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Access\Models\CustomRole;
use Modules\Access\Services\AccessService;
use Modules\Billing\Models\BillingProfile;
use Modules\Billing\Models\Package;
use Modules\Billing\Models\Subscription;
use Modules\Billing\Support\SubscriptionStatus;
use Modules\Identity\Models\MfaFactor;
use Modules\Identity\Models\User;
use Modules\Identity\Models\UserTenant;
use Modules\Identity\Support\IdType;
use Modules\Identity\Support\MembershipStatus;
use Modules\Onboarding\Models\Application;
use Modules\People\Models\StaffCredential;
use Modules\Qualifications\Models\Programme;
use Modules\Qualifications\Models\Qualification;
use Modules\Settings\Models\PlatformSetting;
use Modules\Tenancy\Context\TenantContext;
use Modules\Tenancy\Models\Centre;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Services\TenantService;
use RuntimeException;

/**
 * Local demo data (never in production): Super Admin, Training Young Minds with an
 * owner, a centre manager and a learner. Authenticator secrets are fixed so the demo
 * can be driven by scripts; the password for every account is "demo passphrase 2026".
 *
 *   php artisan db:seed --class=DemoSeeder
 */
final class DemoSeeder extends Seeder
{
    public const PASSWORD = 'demo passphrase 2026';

    public const TOTP_SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    public function run(TenantContext $context, TenantService $tenants, AccessService $access): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DemoSeeder must not run in production.');
        }

        $this->call(DatabaseSeeder::class);

        $context->runAsPlatform(function () use ($context, $tenants, $access): void {
            $admin = $this->person('Naledi', 'Khumalo', 'admin@demo.test', withTotp: true);
            $access->grantPlatformRole($admin, 'super-admin');

            $tym = Tenant::query()->where('code', 'tym')->first()
                ?? $tenants->register(['code' => 'tym', 'legal_name' => 'Training Young Minds (Pty) Ltd', 'trading_name' => 'Training Young Minds']);

            if ($tym->status->value !== 'active') {
                $tenants->approve($tym);
            }

            $tym->forceFill(['branding' => ['primary' => '#4338ca', 'tagline' => "Building South Africa's next generation of artisans"]])->save();

            foreach (['Applied Skills Academy', 'Mzansi Build Institute'] as $i => $name) {
                $code = ['asa', 'mbi'][$i];
                Tenant::query()->where('code', $code)->exists() || $tenants->register(['code' => $code, 'legal_name' => "{$name} (Pty) Ltd", 'trading_name' => $name]);
            }

            $owner = $this->person('Sekal', 'Moyo', 'sekal@demo.test', withTotp: true);
            $manager = $this->person('Thandi', 'Dlamini', 'thandi@demo.test');
            $learner = $this->person('Ayanda', 'Zulu', null, phone: '+27821234567');
            $learner->setIdentityNumber(IdType::SaId, '8001015009087');
            $learner->save();

            $context->runAs($tym, function () use ($owner, $manager, $learner, $access): void {
                $soweto = Centre::query()->firstOrCreate(['name' => 'Soweto Campus'], [
                    'accreditation_number' => '07-QCTO/SDP180825105627', 'accreditation_expires_on' => now()->addYears(3),
                    'scope' => ['101869', '91785'], 'city' => 'Soweto', 'province' => 'Gauteng',
                ]);
                // Ending soon, to show the expiry warning.
                Centre::query()->firstOrCreate(['name' => 'Durban Site'], [
                    'accreditation_number' => '07-QCTO/SDP180825105628', 'accreditation_expires_on' => now()->addDays(45),
                    'scope' => ['101869'], 'city' => 'Durban', 'province' => 'KwaZulu-Natal',
                ]);

                foreach ([[$owner, 'tp-owner', null], [$manager, 'centre-manager', $soweto->id], [$learner, 'learner', $soweto->id]] as [$user, $role, $centre]) {
                    UserTenant::query()->firstOrCreate(['user_id' => $user->id], ['status' => MembershipStatus::Active, 'joined_at' => now(), 'centre_id' => $centre]);
                    $access->assign($user, $role, $centre);
                }

                // Demo plan: Growth (example pricing) in its trial, with invoice details.
                if (! Subscription::query()->exists()) {
                    Subscription::query()->create([
                        'package_id' => Package::query()->where('code', 'growth')->value('id'),
                        'interval' => 'month',
                        'status' => SubscriptionStatus::Trialing,
                        'collection' => 'invoice',
                        'trial_ends_at' => now()->addDays(5),
                    ]);
                }
                BillingProfile::query()->updateOrCreate([], [
                    'legal_name' => 'Training Young Minds (Pty) Ltd', 'vat_number' => '4987654321',
                    'address_line1' => '12 Vilakazi Street', 'city' => 'Soweto', 'province' => 'Gauteng',
                    'postal_code' => '1804', 'email' => 'finance@demo.test',
                ]);
            });

            // Demo supplier details (replace in Settings → Company before real invoices).
            PlatformSetting::query()->firstOrCreate(['key' => 'company'], ['value' => [
                'registered_name' => 'TYM MCS Skills JV (Pty) Ltd (demo)', 'vat_number' => '4123456789',
                'address_line1' => '1 Demo Road', 'city' => 'Johannesburg', 'province' => 'Gauteng', 'postal_code' => '2001',
                'country' => 'ZA', 'email' => 'accounts@demo.test', 'invoice_prefix' => 'DEMO', 'currency' => 'ZAR', 'vat_rate' => 15,
                'bank_details' => "Demo Bank\nAccount 000 000 000\nBranch 000000",
            ]]);

            // The demo provider came in through the accreditation check (shows "Verified").
            Application::query()->firstOrCreate(['reference' => 'APP-DEMOTYM1'], [
                'status' => 'approved', 'tenant_id' => $tym->id, 'token_hash' => hash('sha256', 'demo-tym'),
                'submitted_at' => now()->subDays(20), 'decided_at' => now()->subDays(19), 'email_verified_at' => now()->subDays(20),
                'first_name' => 'Sekal', 'last_name' => 'Moyo', 'email' => 'sekal@demo.test', 'legal_name' => 'Training Young Minds (Pty) Ltd',
                'registration_number' => '2015/123456/07', 'sdp_number' => '07-QCTO/SDP180825105627', 'accreditation_expires_on' => now()->addYears(3),
                'preferred_code' => 'tym', 'consented_at' => now()->subDays(20), 'consent_version' => '2026-11',
                'checklist' => ['cipc_verified' => true, 'letter_matches' => true, 'scope_confirmed' => true, 'contact_confirmed' => true],
            ]);

            // A provider waiting for review in the applications queue.
            Application::query()->firstOrCreate(['reference' => 'APP-DEMO0001'], [
                'status' => 'submitted', 'token_hash' => hash('sha256', 'demo-application'), 'submitted_at' => now()->subHours(5),
                'first_name' => 'Lerato', 'last_name' => 'Mokoena', 'email' => 'lerato@ikhwezi-skills.demo', 'email_verified_at' => now()->subDay(),
                'phone' => '+27831112222', 'legal_name' => 'Ikhwezi Skills Academy (Pty) Ltd', 'trading_name' => 'Ikhwezi Skills',
                'registration_number' => '2017/401122/07', 'website' => 'https://ikhwezi-skills.demo', 'sdp_number' => '07-QCTO/SDP230101100001',
                'accreditation_expires_on' => now()->addYears(4), 'scope' => ['101869'], 'centre_name' => 'Polokwane Centre',
                'address_line1' => '8 Market St', 'city' => 'Polokwane', 'province' => 'Limpopo', 'postal_code' => '0700',
                'preferred_code' => 'ikhwezi', 'interval' => 'month', 'consented_at' => now()->subHours(5), 'consent_version' => '2026-11',
            ]);
        });

        $this->call(CatalogueSeeder::class);
        // The demo provider still offers the legacy GETC: Construction (closed for new
        // enrolments; enrolled learners may finish until 2029).
        $tym = $context->runAsPlatform(fn () => Tenant::query()->where('code', 'tym')->firstOrFail());
        $context->runAs($tym, function (): void {
            $getc = Qualification::query()->where('saqa_id', '49411')->first();
            if ($getc !== null) {
                Programme::query()->firstOrCreate(['qualification_id' => $getc->id], ['modes' => ['contact', 'workplace']]);
            }
        });

        // Staff with registrations: a verified assessor whose registration ends soon, and a
        // facilitator's registration waiting to be checked. Plus a provider-made role.
        $staff = $context->runAsPlatform(fn () => [
            $this->person('Sipho', 'Ndlovu', 'sipho@demo.test'),
            $this->person('Lindiwe', 'Mthembu', 'lindiwe@demo.test'),
        ]);
        $context->runAs($tym, function () use ($staff, $access): void {
            [$sipho, $lindiwe] = $staff;
            $soweto = Centre::query()->where('name', 'Soweto Campus')->firstOrFail();
            $getc = Qualification::query()->where('saqa_id', '49411')->first();
            foreach ([[$sipho, 'assessor'], [$lindiwe, 'facilitator']] as [$person, $role]) {
                UserTenant::query()->firstOrCreate(['user_id' => $person->id], ['status' => MembershipStatus::Active, 'joined_at' => now(), 'centre_id' => $soweto->id]);
                $access->assign($person, $role, $soweto->id);
            }
            if ($getc !== null && ! StaffCredential::query()->exists()) {
                $verified = StaffCredential::query()->create(['user_id' => $sipho->id, 'role' => 'assessor', 'body' => 'CETA', 'registration_number' => 'CETA/ASS/2023/0412',
                    'qualification_ids' => [$getc->id], 'starts_on' => now()->subYears(3), 'expires_on' => now()->addDays(52)]);
                $verified->forceFill(['status' => 'verified', 'verified_at' => now()->subYears(3)])->save();
                StaffCredential::query()->create(['user_id' => $lindiwe->id, 'role' => 'facilitator', 'body' => 'CETA', 'registration_number' => 'CETA/FAC/2026/1187',
                    'qualification_ids' => [$getc->id], 'starts_on' => now()->subMonth(), 'expires_on' => now()->addYears(3)]);
            }
            CustomRole::query()->firstOrCreate(['name' => 'Quality officer'], [
                'key' => 'custom:01jdemoqualityofficer00000', 'description' => 'Checks staff registrations and learner records', 'scope' => 'tenant',
                'permissions' => ['credentials.view', 'credentials.manage', 'tenant.users.view', 'learners.view', 'programmes.view'], 'mfa' => true, 'based_on' => 'centre-manager',
            ]);
        });

        $this->command?->info('Demo ready. Password: '.self::PASSWORD.' · TOTP secret: '.self::TOTP_SECRET);
    }

    private function person(string $first, string $last, ?string $email, bool $withTotp = false, ?string $phone = null): User
    {
        $user = User::query()->when($email, fn ($q) => $q->where('email', $email), fn ($q) => $q->where('phone_e164', $phone))->first()
            ?? User::query()->create([
                'first_name' => $first,
                'last_name' => $last,
                'email' => $email,
                'phone_e164' => $phone,
                'password' => $email ? self::PASSWORD : null,
                'email_verified_at' => $email ? now() : null,
            ]);

        if ($withTotp && ! MfaFactor::query()->where('user_id', $user->id)->exists()) {
            MfaFactor::query()->create(['user_id' => $user->id, 'type' => 'totp', 'secret' => self::TOTP_SECRET, 'confirmed_at' => now(), 'recovery_codes' => []]);
        }

        return $user;
    }
}
