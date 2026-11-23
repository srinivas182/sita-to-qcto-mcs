<?php

declare(strict_types=1);

namespace Database\Seeders;

use Carbon\CarbonImmutable;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Modules\Access\Models\CustomRole;
use Modules\Access\Services\AccessService;
use Modules\Agreements\Models\Agreement;
use Modules\Agreements\Models\Signer;
use Modules\Agreements\Services\AgreementService;
use Modules\Agreements\Services\TemplateService;
use Modules\Billing\Models\BillingProfile;
use Modules\Billing\Models\Package;
use Modules\Billing\Models\Subscription;
use Modules\Billing\Support\SubscriptionStatus;
use Modules\Cohorts\Models\Cohort;
use Modules\Cohorts\Models\CohortStaff;
use Modules\Cohorts\Services\CohortService;
use Modules\Delivery\Models\Room;
use Modules\Delivery\Services\MaterialService;
use Modules\Delivery\Services\TaskService;
use Modules\Delivery\Services\TimetableService;
use Modules\Employers\Models\Employer;
use Modules\Employers\Models\EmployerSite;
use Modules\Employers\Models\Mentor;
use Modules\Enrolment\Models\Application as LearnerApplication;
use Modules\Enrolment\Models\ConsentText;
use Modules\Enrolment\Services\ApplicationPdf;
use Modules\Enrolment\Services\DocumentService;
use Modules\Enrolment\Services\IntakeService;
use Modules\Enrolment\Services\OfferService;
use Modules\Files\Models\File;
use Modules\Files\Storage\StoreFactory;
use Modules\Files\Support\FileStatus;
use Modules\Identity\Models\MfaFactor;
use Modules\Identity\Models\User;
use Modules\Identity\Models\UserTenant;
use Modules\Identity\Support\IdType;
use Modules\Identity\Support\MembershipStatus;
use Modules\Identity\Support\SaIdNumber;
use Modules\Onboarding\Models\Application;
use Modules\People\Models\StaffCredential;
use Modules\People\Services\ProfileService;
use Modules\Qualifications\Models\CentreQualification;
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

    /** Demo only: the learner's signing link (/sign/…) and the signed agreement's check code (/verify/…). */
    public const SIGN_TOKEN = 'DemoSigningLinkForAyandaZulu00000000000000000000';

    public const VERIFY_CODE = 'demosigned';

    /** Demo only: Lerato's offer link (/offer/…). */
    public const OFFER_TOKEN = 'DemoOfferLinkForLeratoNdlovu00000000000000000000';

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
                $soweto = Centre::query()->where('name', 'Soweto Campus')->first();
                if ($soweto !== null) {
                    CentreQualification::query()->firstOrCreate(['centre_id' => $soweto->id, 'qualification_id' => $getc->id], ['expires_on' => $soweto->accreditation_expires_on]);
                }
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

        // A host employer with two workplaces: one approved (two mentors, capacity 20) and one
        // whose approval ends in 45 days (one mentor), so the list shows the warning.
        $context->runAs($tym, function (): void {
            $getc = Qualification::query()->where('saqa_id', '49411')->first();
            if ($getc === null || Employer::query()->exists()) {
                return;
            }
            $mabaso = Employer::query()->create(['legal_name' => 'Mabaso Builders (Pty) Ltd', 'trading_name' => 'Mabaso Builders', 'registration_number' => '2015/123456/07',
                'sdl_number' => 'L123456789', 'seta' => 'CETA', 'bbbee_level' => '2', 'sector' => 'Residential construction',
                'contact_name' => 'Bheki Mabaso', 'contact_email' => 'bheki@mabaso.test', 'contact_phone' => '+27825550101']);
            $yard = EmployerSite::query()->create(['employer_id' => $mabaso->id, 'name' => 'Soweto yard', 'address_line1' => '14 Klipspruit Valley Rd', 'city' => 'Soweto',
                'province' => 'Gauteng', 'postal_code' => '1809', 'approval_number' => 'CETA/WPA/2025/0091', 'approval_body' => 'CETA',
                'approved_on' => now()->subYear(), 'approval_expires_on' => now()->addYears(2), 'approved_qualification_ids' => [$getc->id]]);
            $midrand = EmployerSite::query()->create(['employer_id' => $mabaso->id, 'name' => 'Midrand site', 'city' => 'Midrand', 'province' => 'Gauteng',
                'approval_number' => 'CETA/WPA/2023/0310', 'approval_body' => 'CETA', 'approved_on' => now()->subYears(3), 'approval_expires_on' => now()->addDays(45),
                'approved_qualification_ids' => [$getc->id]]);
            foreach ([['Bheki Mabaso', 'Site foreman', 14], ['Zodwa Khumalo', 'Bricklaying supervisor', 9]] as [$name, $title, $years]) {
                Mentor::query()->create(['employer_id' => $mabaso->id, 'name' => $name, 'designation' => $title, 'years_experience' => $years,
                    'highest_qualification' => 'Bricklayer trade certificate', 'qualification_nqf_level' => 4, 'site_ids' => [$yard->id]]);
            }
            Mentor::query()->create(['employer_id' => $mabaso->id, 'name' => 'Thabo Molefe', 'designation' => 'Site agent', 'years_experience' => 6,
                'highest_qualification' => 'National Diploma: Building', 'qualification_nqf_level' => 6, 'site_ids' => [$midrand->id]]);
        });

        // Bheki, the employer's site foreman, signs in on the app as a workplace mentor.
        $bheki = $context->runAsPlatform(fn () => $this->person('Bheki', 'Mabaso', null, phone: '+27825550101'));
        $context->runAs($tym, function () use ($bheki, $access): void {
            $soweto = Centre::query()->where('name', 'Soweto Campus')->firstOrFail();
            UserTenant::query()->firstOrCreate(['user_id' => $bheki->id], ['status' => MembershipStatus::Active, 'joined_at' => now(), 'centre_id' => $soweto->id]);
            if (! in_array('host-employer', $access->roles($bheki), true)) {
                $access->assign($bheki, 'host-employer', null);
            }
            Mentor::query()->where('name', 'Bheki Mabaso')->update(['user_id' => $bheki->id]);
        });

        // Agreements: the standard workplace agreement published; one waiting for the learner,
        // and one signed by everyone and sealed (its verification page works).
        $sekal = $context->runAsPlatform(fn () => User::query()->where('email', 'sekal@demo.test')->firstOrFail());
        $context->runAs($tym, function () use ($sekal): void {
            if (Agreement::query()->exists()) {
                return;
            }
            $templates = app(TemplateService::class);
            $template = $templates->publish($templates->fromStarter('workplace'), $sekal);
            $templates->publish($templates->fromStarter('employer_mou'), $sekal);
            $templates->publish($templates->fromStarter('learner'), $sekal);
            $mabaso = Employer::query()->where('registration_number', '2015/123456/07')->firstOrFail();
            $yard = EmployerSite::query()->where('employer_id', $mabaso->id)->where('name', 'Soweto yard')->firstOrFail();
            $bheki = Mentor::query()->where('employer_id', $mabaso->id)->where('name', 'Bheki Mabaso')->firstOrFail();
            $getc = Qualification::query()->where('saqa_id', '49411')->firstOrFail();
            $send = fn (string $learner, string $phone) => app(AgreementService::class)->send([
                'template_id' => $template->id, 'employer_id' => $mabaso->id, 'site_id' => $yard->id, 'mentor_id' => $bheki->id, 'qualification_id' => $getc->id,
                'start_date' => now()->addMonth()->startOfMonth()->toDateString(), 'end_date' => now()->addMonths(10)->endOfMonth()->toDateString(),
                'signers' => [
                    ['role' => 'learner', 'name' => $learner, 'phone' => $phone],
                    ['role' => 'employer', 'name' => 'Bheki Mabaso', 'email' => 'bheki@mabaso.test'],
                    ['role' => 'provider', 'name' => 'Sekal Moyo', 'email' => 'sekal@demo.test', 'user_id' => $sekal->id],
                ],
            ], $sekal);

            $waiting = $send('Ayanda Zulu', '+27821234567');
            Signer::query()->where('agreement_id', $waiting->id)->where('role', 'learner')->update(['token_hash' => hash('sha256', self::SIGN_TOKEN)]);
            foreach (Signer::query()->where('agreement_id', $waiting->id)->whereIn('role', ['employer', 'provider'])->get() as $s) {
                $this->signed($s, $waiting);
            }
            $done = $send('Lerato Nkosi', '+27829876543');
            foreach (Signer::query()->where('agreement_id', $done->id)->get() as $s) {
                $this->signed($s, $done);
            }
            $done->forceFill(['verify_code' => self::VERIFY_CODE])->save();
            app(AgreementService::class)->complete($done);
        });

        // A cohort being planned for the legacy GETC: the readiness check shows what blocks it
        // (enrolment closed, a registration not yet verified, no moderator) and what is fine.
        $context->runAs($tym, function () use ($staff): void {
            [$sipho, $lindiwe] = $staff;
            $getc = Qualification::query()->where('saqa_id', '49411')->first();
            if ($getc === null || Cohort::query()->exists()) {
                return;
            }
            $cohorts = app(CohortService::class);
            $cohort = $cohorts->save(['name' => 'GETC: Construction, February 2027', 'qualification_id' => $getc->id,
                'centre_id' => Centre::query()->where('name', 'Soweto Campus')->value('id'), 'mode' => 'contact',
                'intake_on' => now()->addMonths(3)->startOfMonth()->toDateString(), 'planned_end_on' => now()->addMonths(15)->endOfMonth()->toDateString(), 'capacity' => 20], null, null);
            $cohorts->setStaff($cohort, [['user_id' => $lindiwe->id, 'role' => 'facilitator'], ['user_id' => $sipho->id, 'role' => 'assessor']]);
            $yard = EmployerSite::query()->where('name', 'Soweto yard')->firstOrFail();
            $cohorts->setPlacements($cohort, [['site_id' => $yard->id, 'planned_learners' => 20]]);
        });

        // Applications: Bricklayer (93627) takes enrolments until 30 Dec 2026 (SAQA). In this demo
        // database only, it is published so an intake can run; its modules still wait on the QCTO
        // curriculum document. A cohort just before that date, an open intake, and three applicants.
        $bricklayer = $context->runAsPlatform(function () {
            $q = Qualification::query()->where('saqa_id', '93627')->first();
            if ($q !== null && $q->status === 'draft' && $q->last_enrolment_on !== null && $q->last_enrolment_on->gt(now()->addDays(14))) {
                $q->forceFill(['status' => 'published', 'notes' => trim($q->notes."\nDemo database only: published so the intake demo runs; the modules are still to be loaded.")])->save();
            }

            return $q !== null && $q->status === 'published' ? $q : null;
        });
        $applicants = $bricklayer === null ? [] : $context->runAsPlatform(fn () => [
            $this->person('Thando', 'Mokoena', null, phone: '+27831110001'),
            $this->person('Kagiso', 'Molefe', null, phone: '+27831110002'),
            $this->person('Nomsa', 'Dube', null, phone: '+27831110003'),
            $this->person('Lerato', 'Ndlovu', null, phone: '+27831110004'),
            $this->person('Bongani', 'Khumalo', null, phone: '+27831110005'),
        ]);
        $context->runAsPlatform(function () use ($applicants): void {
            foreach (array_map(null, $applicants, ['9505055800089', '9808110200083', '0103035400086', '9001014800089', '9202204720083']) as [$person, $id]) {
                if ($person->id_number === null) {
                    $person->setIdentityNumber(IdType::SaId, $id);
                    $person->dob = Carbon::instance(SaIdNumber::tryParse($id)->dateOfBirth);
                    $person->save();
                }
            }
        });
        $context->runAs($tym, function () use ($bricklayer, $applicants, $sekal): void {
            if ($bricklayer === null || Cohort::query()->where('qualification_id', $bricklayer->id)->exists()) {
                return;
            }
            $soweto = Centre::query()->where('name', 'Soweto Campus')->firstOrFail();
            Programme::query()->firstOrCreate(['qualification_id' => $bricklayer->id], ['modes' => ['contact', 'blended']]);
            CentreQualification::query()->firstOrCreate(['centre_id' => $soweto->id, 'qualification_id' => $bricklayer->id], ['expires_on' => $soweto->accreditation_expires_on]);
            $intakeOn = $bricklayer->last_enrolment_on->copy()->subDays(7);
            $cohort = app(CohortService::class)->save(['name' => 'Bricklayer, December 2026', 'qualification_id' => $bricklayer->id, 'centre_id' => $soweto->id,
                'mode' => 'contact', 'intake_on' => $intakeOn->toDateString(), 'planned_end_on' => $intakeOn->copy()->addYear()->toDateString(), 'capacity' => 20], null, null);
            $intakes = app(IntakeService::class);
            $intake = $intakes->publish($intakes->save([
                'cohort_id' => $cohort->id, 'title' => 'Bricklayer, December 2026', 'opens_on' => now()->toDateString(),
                'closes_on' => $intakeOn->copy()->subDays(5)->toDateString(), 'min_age' => 18, 'document_types' => ['id_document', 'highest_qualification'],
                'description' => "A year of bricklaying at our Soweto campus and on building sites, ending in the NAMB trade test.\nBring your ID and your highest school certificate.",
                'questions' => [['key' => 'employed', 'label' => 'Are you employed at the moment?', 'type' => 'yesno', 'required' => true],
                    ['key' => 'transport', 'label' => 'How will you get to class?', 'type' => 'choice', 'options' => ['Walk', 'Taxi', 'Own transport'], 'required' => false]],
            ], null, null, $sekal));
            $intake->forceFill(['slug' => 'bricklay'])->save(); // a stable demo link: /join/bricklay
            foreach ($applicants as $n => $person) {
                UserTenant::query()->firstOrCreate(['user_id' => $person->id], ['status' => MembershipStatus::Active, 'joined_at' => now(), 'centre_id' => $soweto->id]);
                $a = LearnerApplication::query()->create(['intake_id' => $intake->id, 'user_id' => $person->id, 'reference' => 'APP-DEMO0'.($n + 1),
                    'answers' => ['employed' => $n === 1, 'transport' => ['Taxi', 'Walk', 'Taxi', 'Own car', 'Taxi'][$n]], 'source' => $n === 2 ? 'app' : 'web', 'submitted_at' => now()->subDays(3 - $n)]);
                app(ApplicationPdf::class)->keep($a);
                // Thando's ID is accepted and his certificate waits to be checked; Kagiso's
                // certificate was certified too long ago; Nomsa has added nothing yet.
                if ($n < 2) {
                    $this->demoDocument($person, 'id_document', now()->subDays(12), $n === 0 ? 'accepted' : 'pending', $sekal);
                    $this->demoDocument($person, 'highest_qualification', now()->subDays($n === 0 ? 20 : 130), 'pending', $sekal);
                }
                if ($n === 0) {
                    $a->forceFill(['status' => 'shortlisted', 'decided_at' => now()->subDay(), 'decided_by' => $sekal->id])->save();
                } elseif ($n === 1) {
                    $a->forceFill(['status' => 'waitlisted', 'waitlist_position' => 1, 'decided_at' => now()->subDay(), 'decided_by' => $sekal->id])->save();
                } elseif ($n === 3) {
                    // Lerato's documents are all accepted and she has an open offer.
                    $this->demoDocument($person, 'id_document', now()->subDays(8), 'accepted', $sekal);
                    $this->demoDocument($person, 'highest_qualification', now()->subDays(8), 'accepted', $sekal);
                    $offer = app(OfferService::class)->make($a, ['days' => 7, 'funding' => 'seta_grant'], $sekal)['offer'];
                    $offer->forceFill(['token_hash' => hash('sha256', self::OFFER_TOKEN)])->save();
                } elseif ($n === 4) {
                    // Bongani accepted and signed: he is enrolled, and his QCTO details are partly filled in.
                    $this->demoDocument($person, 'id_document', now()->subDays(9), 'accepted', $sekal);
                    $this->demoDocument($person, 'highest_qualification', now()->subDays(9), 'accepted', $sekal);
                    $offers = app(OfferService::class);
                    $offer = $offers->make($a, ['days' => 7, 'funding' => 'employer'], $sekal)['offer'];
                    $offer = $offers->accept($offer, ['consent' => true, 'consent_version' => ConsentText::current('enrolment')->version], 'web', null);
                    Signer::query()->where('agreement_id', $offer->agreement_id)->update(['status' => 'signed', 'signed_at' => now(), 'signed_name' => 'Signed']);
                    app(AgreementService::class)->complete(Agreement::query()->findOrFail($offer->agreement_id));
                    app(ProfileService::class)->update($person, ['title' => 'Mr', 'home_language' => 'isiZulu', 'address_line1' => '45 Vundla Drive',
                        'address_line2' => 'Jabavu', 'city' => 'Soweto', 'province' => 'Gauteng', 'postal_code' => '1809', 'socioeconomic_status' => '02'], $sekal, true);
                }
            }
            $this->demoTimetable($cohort, $soweto, $bricklayer->id, $sekal);
            $this->demoMaterials($bricklayer->id, $sekal);
        });

        $this->command?->info('Demo ready. Password: '.self::PASSWORD.' · TOTP secret: '.self::TOTP_SECRET);
    }

    /** Demo only: rooms at Soweto, Lindiwe registered for Bricklayer, and the first four weeks of classes. */
    private function demoTimetable(Cohort $cohort, Centre $soweto, string $qualificationId, User $by): void
    {
        if ($soweto->latitude === null) {
            $soweto->forceFill(['latitude' => '-26.2485', 'longitude' => '27.8540'])->save(); // Orlando West, for check-in distances
        }
        $room = Room::query()->firstOrCreate(['centre_id' => $soweto->id, 'name' => 'Room 1'], ['capacity' => 25]);
        $yard = Room::query()->firstOrCreate(['centre_id' => $soweto->id, 'name' => 'Brick yard'], ['kind' => 'outdoor', 'capacity' => 20]);
        Room::query()->firstOrCreate(['centre_id' => $soweto->id, 'name' => 'Computer lab'], ['kind' => 'computer_lab', 'capacity' => 16]);
        /** @var User $lindiwe */
        $lindiwe = User::query()->where('email', 'lindiwe@demo.test')->firstOrFail();
        $c = StaffCredential::query()->create(['user_id' => $lindiwe->id, 'role' => 'facilitator', 'body' => 'CETA', 'registration_number' => 'CETA/FAC/2026/1201',
            'qualification_ids' => [$qualificationId], 'starts_on' => now()->subMonth(), 'expires_on' => now()->addYears(3)]);
        $c->forceFill(['status' => 'verified', 'verified_at' => now()->subWeeks(2)])->save();
        CohortStaff::query()->firstOrCreate(['cohort_id' => $cohort->id, 'user_id' => $lindiwe->id, 'role' => 'facilitator']);
        $timetable = app(TimetableService::class);
        $start = CarbonImmutable::parse($cohort->intake_on)->next('Monday');
        $until = $start->addWeeks(4)->toDateString();
        $timetable->create($cohort, ['kind' => 'knowledge', 'title' => 'Safety on a building site', 'date' => $start->toDateString(), 'start' => '08:00', 'end' => '11:00',
            'room_id' => $room->id, 'facilitator_id' => $lindiwe->id, 'repeat' => ['days' => [1, 3], 'until' => $until]], $by);
        $timetable->create($cohort, ['kind' => 'practical', 'title' => 'Mixing mortar and laying a course', 'date' => $start->toDateString(), 'start' => '12:00', 'end' => '15:30',
            'room_id' => $yard->id, 'facilitator_id' => $lindiwe->id, 'repeat' => ['days' => [2, 4], 'until' => $until]], $by);
    }

    /** Demo only: course notes (a small PDF) and a link for Bricklayer, published; Bongani can sign in with a password. */
    private function demoMaterials(string $qualificationId, User $by): void
    {
        $context = app(TenantContext::class);
        $context->runAsPlatform(fn () => User::query()->where('phone_e164', '+27831110005')->update(['email' => 'bongani@demo.test', 'password' => Hash::make(self::PASSWORD), 'email_verified_at' => now()]));
        $options = new Options;
        $options->setDefaultFont('DejaVu Sans');
        $pdf = new Dompdf($options);
        $pdf->loadHtml('<h1>Safety on a building site</h1><p>Personal protective equipment, scaffolding checks, and keeping the site tidy. Demo notes.</p>');
        $pdf->render();
        $bytes = (string) $pdf->output();
        [$store, $integration] = app(StoreFactory::class)->current();
        $path = $context->tenantId().'/learning_material/demo/safety-notes.pdf';
        $store->disk()->put($path, $bytes);
        $file = File::query()->create(['owner_id' => $by->id, 'purpose' => 'learning_material', 'original_name' => 'safety-notes.pdf', 'mime' => 'application/pdf',
            'size' => strlen($bytes), 'store_driver' => $store->driver(), 'integration_id' => $integration?->id, 'path' => $path, 'status' => 'ready', 'uploaded_at' => now(), 'scanned_at' => now()]);
        $materials = app(MaterialService::class);
        $materials->publish($materials->save(null, ['qualification_id' => $qualificationId, 'title' => 'Safety on a building site: notes', 'kind' => 'document', 'file_id' => $file->id], $by), true);
        $materials->publish($materials->save(null, ['qualification_id' => $qualificationId, 'title' => 'Bricklayer qualification on SAQA', 'kind' => 'link',
            'url' => 'https://regqs.saqa.org.za/viewQualification.php?id=93627'], $by), true);
        $materials->save(null, ['qualification_id' => $qualificationId, 'title' => 'Mortar mixes (draft)', 'kind' => 'link', 'url' => 'https://example.org/mortar'], $by);

        // Two formative tasks; Bongani has answered the quiz and handed in photos waiting to be marked.
        $tasks = app(TaskService::class);
        $quiz = $tasks->publish($tasks->save(null, ['qualification_id' => $qualificationId, 'title' => 'Site safety quiz', 'pass_pct' => 75, 'questions' => [
            ['type' => 'single', 'text' => 'When must you wear a hard hat on site?', 'options' => ['Only near scaffolding', 'At all times on site', 'When the foreman is there'], 'correct' => [1], 'marks' => 2],
            ['type' => 'multi', 'text' => 'Which of these are personal protective equipment?', 'options' => ['Safety boots', 'Sunglasses', 'Gloves', 'Reflective vest'], 'correct' => [0, 2, 3], 'marks' => 2],
        ]], $by), true);
        $evidence = $tasks->publish($tasks->save(null, ['qualification_id' => $qualificationId, 'title' => 'Mix mortar and lay one course', 'pass_pct' => 60, 'evidence' => true,
            'evidence_hint' => 'Two photos: your mortar on the board, and the finished course from the side',
            'questions' => [['type' => 'short', 'text' => 'Why do you wet the bricks before laying them in hot weather?', 'marks' => 4, 'guide' => 'Dry bricks draw water from the mortar, so it cannot cure and bond.']]], $by), true);
        $bongani = $context->runAsPlatform(fn () => User::query()->where('phone_e164', '+27831110005')->first());
        if ($bongani !== null) {
            $tasks->submit($quiz, $bongani, ['answers' => ['q1' => 1, 'q2' => [0, 2, 3]]]);
            $photo = File::query()->create(['owner_id' => $bongani->id, 'purpose' => 'task_evidence', 'original_name' => 'mortar-board.jpg', 'mime' => 'image/jpeg',
                'size' => 8, 'store_driver' => $store->driver(), 'integration_id' => $integration?->id, 'path' => $context->tenantId().'/task_evidence/demo/mortar.jpg', 'status' => 'ready', 'uploaded_at' => now(), 'scanned_at' => now()]);
            $store->disk()->put($photo->path, 'demo jpg');
            $tasks->submit($evidence, $bongani, ['answers' => ['q1' => 'So the bricks do not dry out the mortar.'], 'file_ids' => [$photo->id]]);
        }
    }

    /** Demo only: a drawn "document" photo stored as the person's upload, checked like a real one. */
    private function demoDocument(User $person, string $type, Carbon $certified, string $status, User $by): void
    {
        $img = imagecreatetruecolor(1400, 900);
        imagefill($img, 0, 0, imagecolorallocate($img, 240, 236, 226));
        $ink = imagecolorallocate($img, 30, 32, 40);
        mt_srand(crc32($person->id.$type));
        imagestring($img, 5, 60, 40, strtoupper(str_replace('_', ' ', $type)).' - DEMO COPY', $ink);
        for ($y = 100; $y < 840; $y += 30) {
            for ($x = 60; $x < 1340; $x += mt_rand(9, 16)) {
                imagefilledrectangle($img, $x, $y, $x + mt_rand(3, 8), $y + mt_rand(8, 14), $ink);
            }
        }
        ob_start();
        imagejpeg($img, null, 85);
        $bytes = (string) ob_get_clean();
        $path = $person->id.'/learner_document/demo-'.$type.'.jpg';
        app(StoreFactory::class)->current()[0]->disk()->put($path, $bytes);
        $file = File::query()->create(['owner_id' => $person->id, 'purpose' => 'learner_document', 'original_name' => $type.'.jpg', 'mime' => 'image/jpeg',
            'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'store_driver' => 'local', 'path' => $path, 'status' => FileStatus::Ready, 'uploaded_at' => now(), 'scanned_at' => now()]);
        $doc = app(DocumentService::class)->add($person, ['type' => $type, 'file_id' => $file->id, 'certified' => true,
            'certified_on' => $certified->toDateString(), 'certified_by' => 'SAPS Orlando']);
        if ($status === 'accepted') {
            app(DocumentService::class)->review($doc, 'accept', null, true, $by);
        }
    }

    /** Demo only: record a signature as if it had been made with a PIN. */
    private function signed(Signer $signer, Agreement $agreement): void
    {
        $signer->forceFill([
            'status' => 'signed', 'signed_at' => now()->subHours(3), 'signed_name' => $signer->name, 'ip' => '196.21.45.10',
            'user_agent' => 'Mozilla/5.0 (Linux; Android 14; SM-A155F) Chrome/131.0 Mobile Safari/537.36',
            'evidence' => ['channel' => $signer->phone ? 'whatsapp' : 'email', 'destination' => $signer->maskedPhone() ?? $signer->maskedEmail(),
                'method' => 'one-time PIN', 'document_sha256' => $agreement->body_sha256],
        ])->save();
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
