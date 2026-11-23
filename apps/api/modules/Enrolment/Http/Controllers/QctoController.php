<?php

declare(strict_types=1);

namespace Modules\Enrolment\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Access\Services\AccessService;
use Modules\Audit\AuditLogger;
use Modules\Cohorts\Models\Cohort;
use Modules\Enrolment\Models\QctoOutcome;
use Modules\Enrolment\Models\QctoSubmission;
use Modules\Enrolment\Services\QctoEnrolmentList;
use Modules\Enrolment\Services\QctoResponse;
use Modules\Identity\Models\User;
use Symfony\Component\HttpFoundation\Response;

/** The QCTO learner enrolment list of a cohort: check it, download the file, record that it was sent. */
final class QctoController
{
    public function show(Request $request, string $cohort): JsonResponse
    {
        Gate::authorize('applications.view');

        return response()->json(['data' => $this->list()->build($this->cohort($request, $cohort, 'applications.view'))]);
    }

    public function workbook(Request $request, string $cohort): Response
    {
        Gate::authorize('applications.manage');
        $file = $this->list()->workbook($this->cohort($request, $cohort, 'applications.manage'), $request->boolean('only_unsent'), $request->boolean('acknowledge'));

        return response($file['bytes'], 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$file['name'].'"',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function sent(Request $request, string $cohort): JsonResponse
    {
        Gate::authorize('applications.manage');
        $data = $request->validate(['sent_on' => ['required', 'date', 'before_or_equal:today'], 'note' => ['nullable', 'string', 'max:500']]);
        $c = $this->cohort($request, $cohort, 'applications.manage');
        /** @var User $by */
        $by = $request->user();
        $this->list()->markSent($c, $data['sent_on'], $data['note'] ?? null, $by);

        return response()->json(['data' => $this->list()->build($c)], 201);
    }

    /** Load the QCTO's reply to a submission: their file, or learners marked by hand. */
    public function response(Request $request, string $submission): JsonResponse
    {
        Gate::authorize('applications.manage');
        $s = QctoSubmission::query()->whereKey($submission)->firstOrFail();
        $c = $this->cohort($request, $s->cohort_id, 'applications.manage');
        $request->validate(['file' => ['nullable', 'file', 'max:10240']]);
        /** @var User $by */
        $by = $request->user();
        $counts = app(QctoResponse::class)->load($s, $by, $request->file('file'), $request->except('file'));

        return response()->json(['data' => $this->list()->build($c), 'loaded' => $counts]);
    }

    /** The provider has fixed what the QCTO rejected: the learner goes in the next file. */
    public function resolved(Request $request, string $outcome): JsonResponse
    {
        Gate::authorize('applications.manage');
        $o = QctoOutcome::query()->whereKey($outcome)->where('status', 'rejected')->firstOrFail();
        $s = QctoSubmission::query()->whereKey($o->submission_id)->firstOrFail();
        $c = $this->cohort($request, $s->cohort_id, 'applications.manage');
        /** @var User $by */
        $by = $request->user();
        $o->forceFill(['resolved_at' => now(), 'resolved_by' => $by->id])->save();
        app(AuditLogger::class)->record('qcto.rejection_resolved', $o);

        return response()->json(['data' => $this->list()->build($c)]);
    }

    private function cohort(Request $request, string $id, string $permission): Cohort
    {
        $c = Cohort::query()->whereKey($id)->firstOrFail();
        /** @var User $user */
        $user = $request->user();
        $centres = app(AccessService::class)->centresFor($user, $permission);
        abort_unless($centres === null || in_array($c->centre_id, $centres, true), 404);

        return $c;
    }

    private function list(): QctoEnrolmentList
    {
        return app(QctoEnrolmentList::class);
    }
}
