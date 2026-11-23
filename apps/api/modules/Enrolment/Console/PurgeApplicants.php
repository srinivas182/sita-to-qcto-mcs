<?php

declare(strict_types=1);

namespace Modules\Enrolment\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Audit\AuditLogger;
use Modules\Enrolment\Models\Application;
use Modules\Enrolment\Models\Enrolment;
use Modules\Enrolment\Models\EnrolmentSettings;
use Modules\Enrolment\Models\IdentityCheck;
use Modules\Enrolment\Models\LearnerDocument;
use Modules\Enrolment\Models\Offer;
use Modules\Files\Models\File;
use Modules\Files\Services\FileService;
use Modules\Identity\Models\UserTenant;
use Modules\People\Models\PersonProfile;
use Modules\Tenancy\Context\TenantContext;
use Modules\Tenancy\Models\Tenant;
use Throwable;

/**
 * Nightly, POPIA s14: an applicant who never enrolled and has had no open application for
 * the provider's retention period (12 months by default) is removed from that provider:
 * applications, offers, documents and their files, ID checks, profile and membership.
 * Their platform account stays (they may be with another provider). Consent records stay
 * as proof of what was agreed. Staff and anyone with a role are never touched.
 */
final class PurgeApplicants extends Command
{
    protected $signature = 'enrolment:purge-applicants {--dry-run : Count only}';

    protected $description = 'Remove applicants who never enrolled, after the retention period';

    public function handle(TenantContext $context, AuditLogger $audit): int
    {
        $total = 0;
        foreach ($context->runAsPlatform(fn () => Tenant::query()->where('status', 'active')->get()) as $tenant) {
            $context->runAs($tenant, function () use ($audit, &$total): void {
                $months = EnrolmentSettings::current()->applicant_retention_months;
                $cutoff = now()->subMonths($months);
                $people = Application::query()->select('user_id')->groupBy('user_id')
                    ->havingRaw('max(coalesce(decided_at, submitted_at)) < ?', [$cutoff])->pluck('user_id');
                foreach ($people as $userId) {
                    $stillActive = Application::query()->where('user_id', $userId)->whereIn('status', ['submitted', 'shortlisted', 'waitlisted', 'offered', 'accepted'])->exists()
                        || Enrolment::query()->where('user_id', $userId)->exists()
                        || DB::table('role_assignments')->where('user_id', $userId)->exists();
                    if ($stillActive) {
                        continue;
                    }
                    if (! $this->option('dry-run')) {
                        try {
                            $this->purge((string) $userId);
                            $audit->record('applicant.purged', null, [], ['user' => $userId, 'months' => $months]);
                        } catch (Throwable $e) {
                            report($e);

                            continue;
                        }
                    }
                    $total++;
                }
            });
        }
        $this->info(($this->option('dry-run') ? 'Would remove ' : 'Removed ')."{$total} applicants");

        return self::SUCCESS;
    }

    private function purge(string $userId): void
    {
        $fileIds = LearnerDocument::query()->where('user_id', $userId)->pluck('file_id');
        $files = File::query()->whereIn('id', $fileIds)->get();
        DB::transaction(function () use ($userId): void {
            $applications = Application::query()->where('user_id', $userId)->pluck('id');
            Offer::query()->whereIn('application_id', $applications)->delete();
            Application::query()->whereIn('id', $applications)->delete();
            LearnerDocument::query()->where('user_id', $userId)->delete();
            IdentityCheck::query()->where('user_id', $userId)->delete();
            PersonProfile::query()->where('user_id', $userId)->delete();
            UserTenant::query()->where('user_id', $userId)->delete();
        });
        // Then the files and their stored bytes.
        foreach ($files as $file) {
            try {
                app(FileService::class)->delete($file);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
