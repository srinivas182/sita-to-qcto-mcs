<?php

declare(strict_types=1);

namespace Modules\Agreements\Console;

use Illuminate\Console\Command;
use Modules\Agreements\Models\Agreement;
use Modules\Agreements\Models\Signer;
use Modules\Agreements\Services\AgreementService;
use Modules\Agreements\Services\Invitations;
use Modules\Audit\AuditLogger;
use Modules\Tenancy\Context\TenantContext;
use Modules\Tenancy\Models\Tenant;
use Throwable;

/**
 * Daily: remind people who haven't signed (on day 3 and day 7) and mark agreements past
 * their date as expired. With --advanced, checks advanced agreements with their provider.
 */
final class FollowUp extends Command
{
    protected $signature = 'agreements:follow-up {--advanced : Only check advanced agreements with their provider}';

    protected $description = 'Remind signers, expire old agreements and collect advanced signatures';

    public function handle(TenantContext $context, Invitations $invitations, AgreementService $agreements, AuditLogger $audit): int
    {
        $reminded = $expired = $synced = 0;
        foreach ($context->runAsPlatform(fn () => Tenant::query()->where('status', 'active')->get()) as $tenant) {
            $context->runAs($tenant, function () use ($invitations, $agreements, $audit, &$reminded, &$expired, &$synced): void {
                foreach (Agreement::query()->where('status', 'sent')->get() as $agreement) {
                    if ($agreement->signature_level === 'advanced') {
                        try {
                            $agreements->sync($agreement);
                            $synced++;
                        } catch (Throwable $e) {
                            report($e);
                        }

                        continue;
                    }
                    if ($this->option('advanced')) {
                        continue;
                    }
                    if ($agreement->expires_at !== null && $agreement->expires_at->isPast()) {
                        $agreement->forceFill(['status' => 'expired'])->save();
                        $audit->record('agreement.expired', $agreement);
                        $expired++;

                        continue;
                    }
                    $days = (int) $agreement->sent_at?->diffInDays(now());
                    foreach (Signer::query()->where('agreement_id', $agreement->id)->where('status', 'pending')->get() as $signer) {
                        $due = match (true) {
                            $days >= 7 => 2,
                            $days >= 3 => 1,
                            default => 0,
                        };
                        if ($signer->reminders_sent < $due) {
                            $invitations->send($signer, $agreement, 'reminder');
                            $signer->forceFill(['reminders_sent' => $due])->save();
                            $reminded++;
                        }
                    }
                }
            });
        }
        $this->info("{$reminded} reminders, {$expired} expired, {$synced} advanced checked");

        return self::SUCCESS;
    }
}
