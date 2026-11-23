<?php

declare(strict_types=1);

namespace Modules\Logbook\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Audit\AuditLogger;
use Modules\Cohorts\Models\Cohort;
use Modules\Cohorts\Models\Placement;
use Modules\Delivery\Services\AttendanceService;
use Modules\Employers\Models\EmployerSite;
use Modules\Employers\Models\Mentor;
use Modules\Enrolment\Models\Enrolment;
use Modules\Files\Models\File;
use Modules\Identity\Models\User;
use Modules\Logbook\Models\LearnerPlacement;
use Modules\Logbook\Models\LogbookEntry;
use Modules\Logbook\Models\LogbookRule;
use Modules\Logbook\Support\EntryHash;
use Modules\Qualifications\Models\QualificationModule;

/**
 * The workplace logbook: rules per work experience module, learners placed at a site with a
 * mentor, and entries written on site (often offline) that are chained by hash. A correction
 * is a new entry that replaces the old one; nothing is overwritten.
 */
final class LogbookService
{
    public const TZ = 'Africa/Johannesburg';

    public const MAX_DAY_MINUTES = 14 * 60;

    public function __construct(private readonly AuditLogger $audit) {}

    /** @param  array<string, mixed>  $input */
    public function saveRule(QualificationModule $module, array $input): LogbookRule
    {
        if ($module->kind !== 'WM') {
            throw ValidationException::withMessages(['module' => 'Logbook rules are for work experience modules.']);
        }
        $data = Validator::make($input, [
            'activities' => ['required', 'array', 'min:1', 'max:40'],
            'activities.*.title' => ['required', 'string', 'min:2', 'max:200'],
            'activities.*.photo' => ['sometimes', 'boolean'],
            'activities.*.key' => ['sometimes', 'nullable', 'string', 'max:40'],
            'hours_needed' => ['nullable', 'integer', 'between:1,5000'],
            'evidence_note' => ['nullable', 'string', 'max:500'],
        ])->validate();
        $rule = LogbookRule::query()->firstOrNew(['module_id' => $module->id]);
        $used = $rule->exists ? LogbookEntry::query()->where('module_id', $module->id)->distinct()->pluck('activity_key')->all() : [];
        $activities = [];
        foreach (array_values((array) $data['activities']) as $i => $a) {
            $key = Str::slug((string) ($a['key'] ?? '') ?: 'a'.($i + 1));
            $activities[] = ['key' => substr($key, 0, 40), 'title' => trim((string) $a['title']), 'photo' => (bool) ($a['photo'] ?? false)];
        }
        $missing = array_diff($used, array_column($activities, 'key'));
        if ($missing !== []) {
            throw ValidationException::withMessages(['activities' => 'Learners have logged against an activity you removed. Keep it, or rename it.']);
        }
        $rule->fill(['activities' => $activities, 'hours_needed' => (int) ($data['hours_needed'] ?? $module->credits * 10), 'evidence_note' => $data['evidence_note'] ?? null])->save();
        $this->audit->record('logbook.rules_saved', $rule, [], ['module' => $module->code]);

        return $rule;
    }

    /** @param  array<string, mixed>  $input */
    public function place(Cohort $cohort, array $input): LearnerPlacement
    {
        $data = Validator::make($input, [
            'enrolment_id' => ['required', 'ulid'],
            'site_id' => ['required', 'ulid'],
            'mentor_id' => ['nullable', 'ulid'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
        ])->validate();
        $e = Enrolment::query()->whereKey($data['enrolment_id'])->where('cohort_id', $cohort->id)->whereIn('status', ['active', 'suspended'])->first()
            ?? throw ValidationException::withMessages(['enrolment_id' => 'Choose a learner enrolled in this cohort.']);
        if (! Placement::query()->where('cohort_id', $cohort->id)->where('site_id', $data['site_id'])->exists()) {
            throw ValidationException::withMessages(['site_id' => 'Choose one of the cohort\'s workplaces.']);
        }
        $site = EmployerSite::query()->whereKey($data['site_id'])->firstOrFail();
        if (! empty($data['mentor_id'])) {
            $m = Mentor::query()->whereKey($data['mentor_id'])->where('active', true)->first();
            if ($m === null || ! in_array($site->id, $m->site_ids, true)) {
                throw ValidationException::withMessages(['mentor_id' => 'Choose a mentor at this workplace.']);
            }
        }
        $open = LearnerPlacement::query()->where('enrolment_id', $e->id)->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $data['starts_on']))
            ->whereDate('starts_on', '<=', $data['ends_on'] ?? '9999-12-31')->exists();
        if ($open) {
            throw ValidationException::withMessages(['starts_on' => 'The learner is already placed then. End that placement first.']);
        }
        $p = LearnerPlacement::query()->create(['enrolment_id' => $e->id, 'user_id' => $e->user_id, 'cohort_id' => $cohort->id, 'site_id' => $site->id,
            'mentor_id' => $data['mentor_id'] ?? null, 'starts_on' => $data['starts_on'], 'ends_on' => $data['ends_on'] ?? null]);
        $this->audit->record('logbook.placed', $p, [], ['site' => $site->name]);

        return $p;
    }

    public function endPlacement(LearnerPlacement $p, string $on): LearnerPlacement
    {
        if ($on < $p->starts_on->toDateString()) {
            throw ValidationException::withMessages(['ends_on' => 'A placement cannot end before it starts.']);
        }
        $p->forceFill(['ends_on' => $on])->save();
        $this->audit->record('logbook.placement_ended', $p, [], ['on' => $on]);

        return $p;
    }

    /**
     * An entry from the learner (sent from the phone's outbox; the same client_id twice is
     * the same entry). The phone's hash is checked; if the phone's chain was behind, the
     * server links the entry to the latest one and says so.
     *
     * @param  array<string, mixed>  $input
     * @return array{entry: LogbookEntry, created: bool}
     */
    public function record(User $learner, array $input, ?LogbookEntry $replaces = null): array
    {
        $data = Validator::make($input, [
            'client_id' => ['required', 'uuid'],
            'module_id' => ['required', 'ulid'],
            'activity_key' => ['required', 'string', 'max:40'],
            'work_date' => ['required', 'date_format:Y-m-d'],
            'minutes' => ['required', 'integer', 'between:15,720'],
            'note' => ['nullable', 'string', 'max:3000'],
            'file_ids' => ['nullable', 'array', 'max:6'],
            'file_ids.*' => ['ulid'],
            'photo_hashes' => ['nullable', 'array', 'max:6'],
            'photo_hashes.*' => ['string', 'regex:/^[0-9a-fA-F]{64}$/'],
            'device_time' => ['required', 'date', 'max:40'],
            'prev_hash' => ['nullable', 'regex:/^[0-9a-f]{64}$/'],
            'hash' => ['required', 'regex:/^[0-9a-f]{64}$/'],
            'offline' => ['sometimes', 'boolean'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0'],
        ])->validate();

        $existing = LogbookEntry::query()->where('user_id', $learner->id)->where('client_id', strtolower((string) $data['client_id']))->first();
        if ($existing !== null) {
            return ['entry' => $existing, 'created' => false]; // a retry from the outbox
        }
        $photoHashes = array_values(array_map('strtolower', (array) ($data['photo_hashes'] ?? [])));
        $expected = EntryHash::make($data['prev_hash'] ?? null, (string) $data['client_id'], (string) $data['module_id'], (string) $data['activity_key'], (string) $data['work_date'],
            (int) $data['minutes'], $data['note'] ?? null, $photoHashes, (string) $data['device_time']);
        if (! hash_equals($expected, (string) $data['hash'])) {
            throw ValidationException::withMessages(['hash' => 'This entry was changed after it was written. Write it again.']);
        }
        $date = (string) $data['work_date'];
        if ($date > CarbonImmutable::now(self::TZ)->toDateString()) {
            throw ValidationException::withMessages(['work_date' => 'A logbook entry cannot be for a future day.']);
        }
        $placement = LearnerPlacement::query()->where('user_id', $learner->id)->get()->first(fn (LearnerPlacement $p) => $p->covers($date))
            ?? throw ValidationException::withMessages(['work_date' => 'You were not placed at a workplace on that day.']);
        $cohort = Cohort::query()->whereKey($placement->cohort_id)->firstOrFail();
        $module = QualificationModule::query()->whereKey($data['module_id'])->where('qualification_id', $cohort->qualification_id)->where('kind', 'WM')->first()
            ?? throw ValidationException::withMessages(['module_id' => 'Choose a work experience module of your course.']);
        $rule = LogbookRule::query()->where('module_id', $module->id)->first()
            ?? throw ValidationException::withMessages(['module_id' => 'Your provider has not set up the logbook for this module yet.']);
        $activity = collect($rule->activities)->firstWhere('key', $data['activity_key'])
            ?? throw ValidationException::withMessages(['activity_key' => 'Choose one of the module\'s activities.']);
        $files = array_values(array_unique((array) ($data['file_ids'] ?? [])));
        if ($activity['photo'] && $files === []) {
            throw ValidationException::withMessages(['file_ids' => 'This activity needs a photo of your work.']);
        }
        if ($files !== []) {
            $owned = File::query()->whereIn('id', $files)->where('owner_id', $learner->id)->where('purpose', 'logbook_photo')->where('status', 'ready')->get();
            if ($owned->count() !== count($files)) {
                throw ValidationException::withMessages(['file_ids' => 'Upload the photos again.']);
            }
            $stored = $owned->pluck('sha256')->map(fn ($h) => strtolower((string) $h))->sort()->values()->all();
            $claimed = $photoHashes;
            sort($claimed);
            if ($stored !== $claimed) {
                throw ValidationException::withMessages(['photo_hashes' => 'A photo is not the one taken with this entry.']);
            }
        }
        $dayMinutes = LogbookEntry::query()->where('user_id', $learner->id)->whereDate('work_date', $date)->whereIn('status', LogbookEntry::COUNTED)
            ->when($replaces !== null, fn ($q) => $q->whereKeyNot((string) $replaces?->id))->sum('minutes');
        if ($dayMinutes + (int) $data['minutes'] > self::MAX_DAY_MINUTES) {
            throw ValidationException::withMessages(['minutes' => 'That is more than 14 hours on one day.']);
        }
        [$distance, $ok] = $this->distance($placement, $data);
        $this->signOff()->assertOpen($learner->id, $placement->id, CarbonImmutable::parse($date)->startOfWeek()->toDateString());

        $entry = DB::transaction(function () use ($learner, $data, $placement, $module, $date, $files, $photoHashes, $distance, $ok, $replaces): LogbookEntry {
            DB::table('users')->where('id', $learner->id)->lockForUpdate()->value('id'); // one chain writer per learner at a time
            $last = LogbookEntry::query()->where('user_id', $learner->id)->orderByDesc('seq')->first();
            $prev = $data['prev_hash'] ?? null;
            $rechained = $prev !== $last?->hash;
            $hash = $rechained ? EntryHash::make($last?->hash, (string) $data['client_id'], $module->id, (string) $data['activity_key'], $date, (int) $data['minutes'],
                $data['note'] ?? null, $photoHashes, (string) $data['device_time']) : (string) $data['hash'];
            $e = LogbookEntry::query()->create([
                'user_id' => $learner->id, 'learner_placement_id' => $placement->id, 'module_id' => $module->id, 'activity_key' => $data['activity_key'],
                'work_date' => $date, 'week_start' => CarbonImmutable::parse($date)->startOfWeek()->toDateString(), 'minutes' => (int) $data['minutes'],
                'note' => isset($data['note']) ? trim((string) $data['note']) : null, 'file_ids' => $files, 'photo_hashes' => $photoHashes,
                'distance_m' => $distance, 'location_ok' => $ok, 'offline' => (bool) ($data['offline'] ?? false), 'device_time' => CarbonImmutable::parse((string) $data['device_time']), 'device_time_text' => (string) $data['device_time'],
                'client_id' => strtolower((string) $data['client_id']), 'seq' => ($last->seq ?? 0) + 1, 'prev_hash' => $last?->hash, 'hash' => $hash,
                'rechained' => $rechained, 'replaces_id' => $replaces?->id,
            ]);
            $replaces?->forceFill(['status' => 'corrected'])->save();
            $this->signOff()->weekFor($e);
            if ($replaces !== null) {
                $this->signOff()->weekFor($replaces); // reopens the old week once nothing sent back is left
            }

            return $e;
        });
        $this->audit->record($replaces ? 'logbook.corrected' : 'logbook.entry', $entry, [], ['minutes' => $entry->minutes, 'offline' => $entry->offline]);

        return ['entry' => $entry, 'created' => true];
    }

    /**
     * Correct an entry not yet signed: a new entry replaces it (both stay in the chain).
     *
     * @param  array<string, mixed>  $input
     */
    public function correct(LogbookEntry $old, User $learner, array $input): LogbookEntry
    {
        if ($old->user_id !== $learner->id) {
            abort(404);
        }
        if (! in_array($old->status, ['submitted', 'returned'], true)) {
            throw ValidationException::withMessages(['entry' => 'A signed entry cannot be changed. Ask your mentor or provider.']);
        }

        return $this->record($learner, $input, $old)['entry'];
    }

    /**
     * Chain check: every entry links to the one before and its hash is right.
     *
     * @return array{ok: bool, entries: int, broken_at: int|null}
     */
    public function verify(string $userId): array
    {
        $prev = null;
        $n = 0;
        foreach (LogbookEntry::query()->where('user_id', $userId)->orderBy('seq')->cursor() as $e) {
            $n++;
            $hash = EntryHash::make($e->prev_hash, $e->client_id, $e->module_id, $e->activity_key, $e->work_date->toDateString(), $e->minutes, $e->note,
                $e->photo_hashes, $e->device_time_text);
            if ($e->prev_hash !== $prev || ! hash_equals($hash, $e->hash)) {
                return ['ok' => false, 'entries' => $n, 'broken_at' => $e->seq];
            }
            $prev = $e->hash;
        }

        return ['ok' => true, 'entries' => $n, 'broken_at' => null];
    }

    /**
     * Hours per work experience module against what is needed, for a learner.
     *
     * @return list<array{module_id: string, code: string, title: string, hours_needed: int|null, hours: float, signed_hours: float}>
     */
    public function hours(string $userId, string $qualificationId): array
    {
        $modules = QualificationModule::query()->where('qualification_id', $qualificationId)->where('kind', 'WM')->orderBy('sort')->get();
        $rules = LogbookRule::query()->whereIn('module_id', $modules->pluck('id'))->get()->keyBy('module_id');
        $entries = LogbookEntry::query()->where('user_id', $userId)->whereIn('status', LogbookEntry::COUNTED)->get()->groupBy('module_id');

        return array_values($modules->map(fn (QualificationModule $m) => ['module_id' => $m->id, 'code' => $m->code, 'title' => $m->title,
            'hours_needed' => $rules->get($m->id)?->hours_needed, 'hours' => round(($entries->get($m->id)?->sum('minutes') ?? 0) / 60, 1),
            'signed_hours' => round(($entries->get($m->id)?->where('status', 'signed')->sum('minutes') ?? 0) / 60, 1)])->all());
    }

    private function signOff(): SignOffService
    {
        return app(SignOffService::class);
    }

    /** @return array<string, mixed> */
    public static function present(LogbookEntry $e): array
    {
        return ['id' => $e->id, 'client_id' => $e->client_id, 'module_id' => $e->module_id, 'activity_key' => $e->activity_key, 'work_date' => $e->work_date->toDateString(),
            'week_start' => $e->week_start->toDateString(), 'minutes' => $e->minutes, 'note' => $e->note, 'file_ids' => $e->file_ids, 'distance_m' => $e->distance_m,
            'location_ok' => $e->location_ok, 'offline' => $e->offline, 'status' => $e->status, 'comment' => $e->comment, 'seq' => $e->seq, 'hash' => $e->hash,
            'rechained' => $e->rechained, 'replaces_id' => $e->replaces_id];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: int|null, 1: bool|null}
     */
    private function distance(LearnerPlacement $p, array $data): array
    {
        $site = EmployerSite::query()->whereKey($p->site_id)->first();
        if ($site?->latitude === null || $site->longitude === null || ! isset($data['latitude'], $data['longitude'])) {
            return [null, null];
        }
        $d = (int) round(AttendanceService::metres((float) $site->latitude, (float) $site->longitude, (float) $data['latitude'], (float) $data['longitude']));

        return [$d, $d - min(100.0, (float) ($data['accuracy'] ?? 0)) <= 500];
    }
}
