<?php

declare(strict_types=1);

namespace Modules\Logbook\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Modules\Audit\AuditLogger;
use Modules\Employers\Models\Mentor;
use Modules\Enrolment\Models\Enrolment;
use Modules\Identity\Models\Device;
use Modules\Identity\Models\User;
use Modules\Identity\Push\PushService;
use Modules\Logbook\Models\LearnerPlacement;
use Modules\Logbook\Models\LogbookEntry;
use Modules\Logbook\Models\LogbookRule;
use Modules\Logbook\Models\LogbookSignoff;
use Modules\Logbook\Models\LogbookWeek;
use Throwable;

/**
 * The mentor's weekly sign-off. A week groups a learner's entries at one placement; the mentor
 * signs it (the signature covers the entries' hashes, so later changes show) or sends it back
 * with a comment on the entries to fix.
 */
final class SignOffService
{
    public function __construct(private readonly AuditLogger $audit, private readonly PushService $pushes) {}

    /** The week an entry belongs to; a signed week takes no more entries. */
    public function weekFor(LogbookEntry $e): LogbookWeek
    {
        $w = LogbookWeek::query()->firstOrCreate(['user_id' => $e->user_id, 'learner_placement_id' => $e->learner_placement_id, 'week_start' => $e->week_start->toDateString()]);
        if ($w->status === 'returned' && ! LogbookEntry::query()->where('user_id', $w->user_id)->where('learner_placement_id', $w->learner_placement_id)
            ->whereDate('week_start', $w->week_start)->where('status', 'returned')->exists()) {
            $w->forceFill(['status' => 'open'])->save(); // everything sent back has been corrected
        }

        return $w;
    }

    public function assertOpen(string $userId, string $placementId, string $weekStart): void
    {
        $signed = LogbookWeek::query()->where('user_id', $userId)->where('learner_placement_id', $placementId)->whereDate('week_start', $weekStart)->where('status', 'signed')->exists();
        if ($signed) {
            throw ValidationException::withMessages(['work_date' => 'Your mentor has already signed that week.']);
        }
    }

    /**
     * The mentor records (with a user account) for the signed-in user, or none.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Mentor>
     */
    public function mentorsOf(User $user): \Illuminate\Database\Eloquent\Collection
    {
        return Mentor::query()->where('user_id', $user->id)->where('active', true)->get();
    }

    /** A week the user may sign as the learner's mentor, or 404. */
    public function mentorWeek(User $user, string $id): LogbookWeek
    {
        $w = LogbookWeek::query()->whereKey($id)->firstOrFail();
        abort_unless($this->mentorFor($user, $w) !== null, 404);

        return $w;
    }

    public function mentorFor(User $user, LogbookWeek $w): ?Mentor
    {
        $p = LearnerPlacement::query()->whereKey($w->learner_placement_id)->first();

        return $p?->mentor_id ? $this->mentorsOf($user)->firstWhere('id', $p->mentor_id) : null;
    }

    /** @return Collection<int, LogbookEntry> */
    public function entries(LogbookWeek $w): Collection
    {
        return LogbookEntry::query()->where('user_id', $w->user_id)->where('learner_placement_id', $w->learner_placement_id)
            ->whereDate('week_start', $w->week_start)->where('status', '!=', 'corrected')->orderBy('work_date')->orderBy('seq')->get();
    }

    /** @param  array<string, mixed>  $input */
    public function sign(LogbookWeek $w, User $by, array $input): LogbookWeek
    {
        Validator::make($input, ['confirm' => ['accepted']], ['confirm.accepted' => 'Confirm that the learner did this work.'])->validate();
        $mentor = $this->mentorFor($by, $w) ?? abort(404);
        if ($w->status === 'signed') {
            throw ValidationException::withMessages(['week' => 'This week is already signed.']);
        }
        $entries = $this->entries($w);
        if ($entries->isEmpty()) {
            throw ValidationException::withMessages(['week' => 'There is nothing to sign this week.']);
        }
        if ($entries->contains('status', 'returned')) {
            throw ValidationException::withMessages(['week' => 'The learner has not fixed the entries you sent back yet.']);
        }
        if ($w->week_start->toDateString() > CarbonImmutable::now(LogbookService::TZ)->startOfWeek()->toDateString()) {
            throw ValidationException::withMessages(['week' => 'This week has not started yet.']);
        }
        DB::transaction(function () use ($w, $by, $mentor, $entries): void {
            LogbookEntry::query()->whereIn('id', $entries->pluck('id'))->update(['status' => 'signed', 'comment' => null]);
            $w->forceFill(['status' => 'signed', 'signed_by' => $by->id, 'mentor_id' => $mentor->id, 'signed_at' => now(), 'comment' => null,
                'signature' => self::signature($entries)])->save();
        });
        $this->audit->record('logbook.week_signed', $w, [], ['entries' => $entries->count(), 'minutes' => (int) $entries->sum('minutes')]);
        $this->tell($w->user_id, 'Week signed', "{$mentor->name} signed your logbook for the week of {$w->week_start->format('j M')}.", $w);

        return $w;
    }

    /** @param  array<string, mixed>  $input */
    public function return(LogbookWeek $w, User $by, array $input): LogbookWeek
    {
        $data = Validator::make($input, [
            'comment' => ['required', 'string', 'min:5', 'max:1000'],
            'entry_ids' => ['nullable', 'array'],
            'entry_ids.*' => ['ulid'],
        ])->validate();
        $mentor = $this->mentorFor($by, $w) ?? abort(404);
        if ($w->status === 'signed') {
            throw ValidationException::withMessages(['week' => 'A signed week cannot be sent back.']);
        }
        $entries = $this->entries($w);
        $ids = array_values(array_intersect((array) ($data['entry_ids'] ?? []), $entries->pluck('id')->all())) ?: $entries->pluck('id')->all();
        if ($ids === []) {
            throw ValidationException::withMessages(['week' => 'There is nothing to send back this week.']);
        }
        DB::transaction(function () use ($w, $ids, $data): void {
            LogbookEntry::query()->whereIn('id', $ids)->update(['status' => 'returned', 'comment' => $data['comment']]);
            $w->forceFill(['status' => 'returned', 'comment' => $data['comment']])->save();
        });
        $this->audit->record('logbook.week_returned', $w, [], ['entries' => count($ids)]);
        $this->tell($w->user_id, 'Logbook sent back', "{$mentor->name}: {$data['comment']}", $w);

        return $w;
    }

    /**
     * The mentor's final sign-off for a learner's work experience module: every entry for the
     * module is signed, the hours needed are reached, and the mentor confirms authenticity.
     *
     * @param  array<string, mixed>  $input
     */
    public function final(User $by, array $input): LogbookSignoff
    {
        $data = Validator::make($input, [
            'enrolment_id' => ['required', 'ulid'],
            'module_id' => ['required', 'ulid'],
            'authentic' => ['accepted'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ], ['authentic.accepted' => 'Confirm that the logbook is true.'])->validate();
        $enrolment = Enrolment::query()->whereKey($data['enrolment_id'])->firstOrFail();
        $mentorIds = $this->mentorsOf($by)->pluck('id');
        $placement = LearnerPlacement::query()->where('enrolment_id', $enrolment->id)->whereIn('mentor_id', $mentorIds)->orderByDesc('starts_on')->first() ?? abort(404);
        $rule = LogbookRule::query()->where('module_id', $data['module_id'])->first()
            ?? throw ValidationException::withMessages(['module_id' => 'This module has no logbook.']);
        $existing = LogbookSignoff::query()->where('enrolment_id', $enrolment->id)->where('module_id', $rule->module_id)->first();
        if ($existing !== null && $existing->status !== 'queried') {
            throw ValidationException::withMessages(['module_id' => 'You have already signed off this module.']);
        }
        $entries = LogbookEntry::query()->where('user_id', $enrolment->user_id)->where('module_id', $rule->module_id)->where('status', '!=', 'corrected')->orderBy('seq')->get();
        if ($entries->whereIn('status', ['submitted', 'returned'])->isNotEmpty()) {
            throw ValidationException::withMessages(['module_id' => 'Sign or send back the open weeks first.']);
        }
        $minutes = (int) $entries->sum('minutes');
        if ($minutes < $rule->hours_needed * 60) {
            throw ValidationException::withMessages(['module_id' => sprintf('The learner has %s of the %d hours needed.', round($minutes / 60, 1), $rule->hours_needed)]);
        }
        $mentor = $this->mentorsOf($by)->firstWhere('id', $placement->mentor_id);
        $s = $existing ?? new LogbookSignoff(['enrolment_id' => $enrolment->id, 'user_id' => $enrolment->user_id, 'module_id' => $rule->module_id]);
        $s->fill(['mentor_id' => $mentor?->id, 'signed_by' => $by->id, 'signed_at' => now(), 'minutes' => $minutes, 'signature' => self::signature($entries),
            'statement_version' => LogbookSignoff::STATEMENT_VERSION, 'mentor_comment' => $data['comment'] ?? null,
            'status' => 'signed', 'checked_by' => null, 'checked_at' => null, 'check_comment' => null])->save();
        $this->audit->record('logbook.final_signoff', $s, [], ['minutes' => $minutes, 'statement' => LogbookSignoff::STATEMENT_VERSION]);
        $this->tell($enrolment->user_id, 'Module signed off', "{$mentor?->name} signed off your workplace logbook for this module.", null);

        return $s;
    }

    /**
     * The provider's assessor checks the signed-off logbook: confirmed, or queried with a
     * comment (the mentor then signs off again once it is sorted out).
     *
     * @param  array<string, mixed>  $input
     */
    public function check(LogbookSignoff $s, User $by, array $input): LogbookSignoff
    {
        $data = Validator::make($input, [
            'outcome' => ['required', 'in:checked,queried'],
            'comment' => ['required_if:outcome,queried', 'nullable', 'string', 'max:1000'],
        ], ['comment.required_if' => 'Say what needs looking into.'])->validate();
        if ($s->status !== 'signed') {
            throw ValidationException::withMessages(['outcome' => 'This sign-off is not waiting for a check.']);
        }
        $entries = LogbookEntry::query()->where('user_id', $s->user_id)->where('module_id', $s->module_id)->where('status', 'signed')->get();
        if ($data['outcome'] === 'checked' && ! hash_equals($s->signature, self::signature($entries))) {
            throw ValidationException::withMessages(['outcome' => 'The logbook changed after the mentor signed it off. Query it instead.']);
        }
        $s->forceFill(['status' => $data['outcome'], 'checked_by' => $by->id, 'checked_at' => now(), 'check_comment' => $data['comment'] ?? null])->save();
        $this->audit->record('logbook.signoff_'.$data['outcome'], $s);

        return $s;
    }

    /** @return array<string, mixed> */
    public static function presentSignoff(LogbookSignoff $s): array
    {
        return ['id' => $s->id, 'enrolment_id' => $s->enrolment_id, 'user_id' => $s->user_id, 'module_id' => $s->module_id, 'status' => $s->status,
            'hours' => round($s->minutes / 60, 1), 'signed_at' => $s->signed_at->toIso8601String(), 'mentor_comment' => $s->mentor_comment,
            'checked_at' => $s->checked_at?->toIso8601String(), 'check_comment' => $s->check_comment];
    }

    /** Whether the signature still matches the entries (an entry changed after signing shows). */
    public function intact(LogbookWeek $w): bool
    {
        return $w->status !== 'signed' || hash_equals((string) $w->signature, self::signature($this->entries($w)->where('status', 'signed')));
    }

    /** @param  Collection<int, LogbookEntry>  $entries */
    public static function signature(Collection $entries): string
    {
        return hash('sha256', 'w1|'.$entries->sortBy('seq')->map(fn (LogbookEntry $e) => $e->hash)->implode(','));
    }

    /** @return array<string, mixed> */
    public static function present(LogbookWeek $w): array
    {
        return ['id' => $w->id, 'user_id' => $w->user_id, 'learner_placement_id' => $w->learner_placement_id, 'week_start' => $w->week_start->toDateString(),
            'status' => $w->status, 'signed_at' => $w->signed_at?->toIso8601String(), 'comment' => $w->comment];
    }

    private function tell(string $userId, string $title, string $body, ?LogbookWeek $w): void
    {
        foreach (Device::query()->where('user_id', $userId)->whereNull('revoked_at')->whereNotNull('push_token')->get() as $d) {
            try {
                $this->pushes->send($d, $title, $body, array_filter(['type' => 'logbook', 'week_id' => $w?->id]));
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
