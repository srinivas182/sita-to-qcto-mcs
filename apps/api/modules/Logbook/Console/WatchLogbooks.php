<?php

declare(strict_types=1);

namespace Modules\Logbook\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Modules\Audit\AuditLogger;
use Modules\Employers\Models\Mentor;
use Modules\Identity\Models\Device;
use Modules\Identity\Models\User;
use Modules\Identity\Push\PushService;
use Modules\Logbook\Models\LearnerPlacement;
use Modules\Logbook\Models\LogbookEntry;
use Modules\Logbook\Models\LogbookWeek;
use Modules\Logbook\Services\LogbookService;
use Modules\Messaging\Services\MessageService;
use Modules\Tenancy\Context\TenantContext;
use Modules\Tenancy\Models\Tenant;
use Throwable;

/**
 * Daily: remind mentors of weeks left unsigned for 7 days after the week ended (then weekly),
 * and nudge learners on a placement who have logged nothing for 14 days (then every 14 days).
 */
final class WatchLogbooks extends Command
{
    public const MENTOR_DAYS = 7;

    public const LEARNER_DAYS = 14;

    protected $signature = 'logbook:watch';

    protected $description = 'Remind mentors of unsigned logbook weeks and nudge learners who stopped logging';

    public function handle(TenantContext $context, MessageService $messages, PushService $pushes, AuditLogger $audit): int
    {
        $reminded = $nudged = 0;
        $today = CarbonImmutable::now(LogbookService::TZ)->startOfDay();
        foreach ($context->runAsPlatform(fn () => Tenant::query()->where('status', 'active')->get()) as $tenant) {
            $context->runAs($tenant, function () use ($tenant, $context, $messages, $pushes, $audit, $today, &$reminded, &$nudged): void {
                // Mentors: one message per mentor listing their learners with weeks waiting.
                $due = LogbookWeek::query()->where('status', 'open')->whereDate('week_start', '<=', $today->subDays(self::MENTOR_DAYS + 6)->toDateString())
                    ->where(fn ($q) => $q->whereNull('reminded_at')->orWhere('reminded_at', '<=', now()->subDays(self::MENTOR_DAYS)))->get()
                    ->filter(fn (LogbookWeek $w) => LogbookEntry::query()->where('learner_placement_id', $w->learner_placement_id)->whereDate('week_start', $w->week_start)->where('status', 'submitted')->exists());
                $placements = LearnerPlacement::query()->whereIn('id', $due->pluck('learner_placement_id'))->get()->keyBy('id');
                foreach ($due->groupBy(fn (LogbookWeek $w) => (string) $placements->get($w->learner_placement_id)?->mentor_id) as $mentorId => $weeks) {
                    $mentor = $mentorId !== '' ? Mentor::query()->whereKey($mentorId)->first() : null;
                    if ($mentor === null) {
                        continue;
                    }
                    $text = "{$tenant->trading_name}: {$weeks->count()} logbook ".($weeks->count() === 1 ? 'week is' : 'weeks are').' waiting for your signature. Open the app to sign or send back.';
                    $this->reach($context, $messages, $pushes, $mentor->user_id, null, 'Logbooks to sign', $text, 'logbook_mentor');
                    LogbookWeek::query()->whereIn('id', $weeks->pluck('id'))->update(['reminded_at' => now()]);
                    $reminded += $weeks->count();
                }

                // Learners on a placement today with nothing logged for 14 days.
                foreach (LearnerPlacement::query()->whereDate('starts_on', '<=', $today->subDays(self::LEARNER_DAYS)->toDateString())
                    ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $today->toDateString()))
                    ->where(fn ($q) => $q->whereNull('nudged_at')->orWhere('nudged_at', '<=', now()->subDays(self::LEARNER_DAYS)))->get() as $p) {
                    $last = LogbookEntry::query()->where('learner_placement_id', $p->id)->max('created_at');
                    if ($last !== null && CarbonImmutable::parse((string) $last)->gt(now()->subDays(self::LEARNER_DAYS))) {
                        continue;
                    }
                    $this->reach($context, $messages, $pushes, $p->user_id, null, 'Your logbook', "{$tenant->trading_name}: you have not logged any work on site for two weeks. Log each day in the app, even without signal.", 'logbook');
                    $p->forceFill(['nudged_at' => now()])->saveQuietly();
                    $audit->record('logbook.inactive', $p);
                    $nudged++;
                }
            });
        }
        $this->info("Reminded mentors of {$reminded} weeks; nudged {$nudged} learners.");

        return self::SUCCESS;
    }

    /** Push to the person's phones; SMS when they have no app. */
    private function reach(TenantContext $context, MessageService $messages, PushService $pushes, ?string $userId, ?string $phone, string $title, string $text, string $type): void
    {
        $devices = $userId ? Device::query()->where('user_id', $userId)->whereNull('revoked_at')->whereNotNull('push_token')->get() : collect();
        foreach ($devices as $d) {
            try {
                $pushes->send($d, $title, $text, ['type' => $type]);
            } catch (Throwable $e) {
                report($e);
            }
        }
        if ($devices->isEmpty()) {
            $phone ??= $userId ? $context->runAsPlatform(fn () => User::query()->whereKey($userId)->value('phone_e164')) : null;
            if (is_string($phone)) {
                $messages->notify($phone, $text, 'logbook');
            }
        }
    }
}
