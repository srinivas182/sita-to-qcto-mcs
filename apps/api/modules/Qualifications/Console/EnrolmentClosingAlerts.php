<?php

declare(strict_types=1);

namespace Modules\Qualifications\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Modules\Audit\AuditLogger;
use Modules\Qualifications\Models\Programme;
use Modules\Qualifications\Models\Qualification;
use Modules\Qualifications\Notifications\EnrolmentClosing;
use Modules\Tenancy\Context\TenantContext;
use Modules\Tenancy\Models\Tenant;

/**
 * Daily: tells owners 180, 90 and 30 days before a qualification they offer closes
 * for new enrolments, and when it has closed (once per threshold), naming its successors.
 */
final class EnrolmentClosingAlerts extends Command
{
    protected $signature = 'qualifications:enrolment-alerts';

    protected $description = 'Email providers whose qualifications are closing for new enrolments';

    public function handle(TenantContext $context, AuditLogger $audit): int
    {
        $sent = 0;
        $closing = $context->runAsPlatform(fn () => Qualification::query()->where('status', 'published')->whereNotNull('last_enrolment_on')
            ->whereDate('last_enrolment_on', '<=', now()->addDays(Qualification::CLOSING_DAYS))
            ->whereDate('last_enrolment_on', '>=', now()->subDays(30))->get()->keyBy('id'));
        if ($closing->isEmpty()) {
            $this->info('0 alerts sent');

            return self::SUCCESS;
        }
        $successorNames = $context->runAsPlatform(fn () => Qualification::query()
            ->whereIn('id', $closing->pluck('successor_ids')->flatten()->filter()->unique()->all())->get()
            ->mapWithKeys(fn (Qualification $q) => [$q->id => trim("{$q->title} (SAQA {$q->saqa_id})")]));

        $tenants = $context->runAsPlatform(fn () => Tenant::query()->where('status', 'active')->get());
        foreach ($tenants as $tenant) {
            $context->runAs($tenant, function () use ($tenant, $closing, $successorNames, $audit, &$sent): void {
                $programmes = Programme::query()->where('active', true)->whereIn('qualification_id', $closing->keys())->get();
                foreach ($programmes as $programme) {
                    /** @var Qualification $q */
                    $q = $closing[$programme->qualification_id];
                    $days = (int) now()->startOfDay()->diffInDays($q->last_enrolment_on, false);
                    $threshold = match (true) {
                        $days < 0 => 0,
                        $days <= 30 => 30,
                        $days <= 90 => 90,
                        default => 180,
                    };
                    if ($programme->alerted_for !== null && $programme->alerted_for <= $threshold) {
                        continue;
                    }

                    $emails = DB::table('role_assignments as r')->join('users as u', 'u.id', '=', 'r.user_id')
                        ->where('r.role', 'tp-owner')->whereNotNull('u.email')->distinct()->pluck('u.email');
                    $successors = [];
                    foreach ($q->successor_ids ?? [] as $id) {
                        $name = $successorNames->get($id);
                        if (is_string($name)) {
                            $successors[] = $name;
                        }
                    }
                    foreach ($emails as $email) {
                        Notification::route('mail', (string) $email)->notify(new EnrolmentClosing(
                            $tenant->trading_name, $tenant->code, $q->title, (string) $q->last_enrolment_on?->format('j F Y'),
                            (string) $q->last_achievement_on?->format('j F Y'), $days, $successors,
                        ));
                    }

                    $programme->forceFill(['alerted_for' => $threshold])->saveQuietly();
                    $audit->record('programme.enrolment_alert', $programme, meta: ['days' => $days, 'threshold' => $threshold]);
                    $sent++;
                }
            });
        }

        $this->info("{$sent} alerts sent");

        return self::SUCCESS;
    }
}
