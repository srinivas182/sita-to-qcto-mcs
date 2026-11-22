<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Access\Services\AccessService;
use Modules\Identity\Models\MfaFactor;
use Modules\Identity\Models\User;
use Modules\Identity\Models\UserTenant;
use Modules\Identity\Support\IdType;
use Modules\Identity\Support\MembershipStatus;
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
                $soweto = Centre::query()->firstOrCreate(['name' => 'Soweto Campus'], ['accreditation_number' => 'QCTO-SDP-100245', 'accreditation_expires_on' => now()->addYears(3)]);
                Centre::query()->firstOrCreate(['name' => 'Durban Site'], ['accreditation_number' => 'QCTO-SDP-100246', 'accreditation_expires_on' => now()->addYears(2)]);

                foreach ([[$owner, 'tp-owner', null], [$manager, 'centre-manager', $soweto->id], [$learner, 'learner', $soweto->id]] as [$user, $role, $centre]) {
                    UserTenant::query()->firstOrCreate(['user_id' => $user->id], ['status' => MembershipStatus::Active, 'joined_at' => now(), 'centre_id' => $centre]);
                    $access->assign($user, $role, $centre);
                }
            });
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
