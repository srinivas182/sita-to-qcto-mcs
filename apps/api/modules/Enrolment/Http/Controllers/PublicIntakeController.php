<?php

declare(strict_types=1);

namespace Modules\Enrolment\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Cohorts\Models\Cohort;
use Modules\Enrolment\Models\Intake;
use Modules\Enrolment\Services\ApplicationService;
use Modules\Identity\Models\User;
use Modules\Identity\Services\SignIn;
use Modules\Qualifications\Models\Qualification;
use Modules\Tenancy\Context\TenantContext;
use Modules\Tenancy\Models\Centre;
use Modules\Tenancy\Models\Tenant;

/** Public: an intake's page and applying without an account (the phone is proven by a PIN). */
final class PublicIntakeController
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => Intake::query()->where('status', 'open')->orderBy('closes_on')->get()
            ->filter(fn (Intake $i) => $i->accepting())->map(fn (Intake $i) => $this->present($i))->values()->all()]);
    }

    public function show(string $slug): JsonResponse
    {
        $intake = Intake::query()->where('slug', strtolower($slug))->whereIn('status', ['open', 'closed'])->firstOrFail();

        return response()->json(['data' => $this->present($intake)]);
    }

    public function code(Request $request, string $slug): JsonResponse
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:20']]);

        return response()->json(['data' => app(ApplicationService::class)->requestCode($this->open($slug), (string) $data['phone'])]);
    }

    public function apply(Request $request, string $slug): JsonResponse
    {
        if ($request->filled('website')) { // honeypot: people never fill it
            return response()->json(['data' => ['reference' => 'APP-'.strtoupper(substr(md5((string) $request->ip()), 0, 6))]], 201);
        }
        $application = app(ApplicationService::class)->applyPublic($this->open($slug), $request->all(), $request->ip());
        // The PIN proved the phone, as at sign-in: in a browser, sign them in so they can add
        // their documents now. (The app signs in its own way.)
        $signedIn = false;
        if ($request->hasSession()) {
            $user = User::query()->whereKey($application->user_id)->firstOrFail();
            app(SignIn::class)->complete($request, $user, 'otp');
            $signedIn = true;
        }

        return response()->json(['data' => ['id' => $application->id, 'reference' => $application->reference, 'status' => $application->status, 'signed_in' => $signedIn]], 201);
    }

    private function open(string $slug): Intake
    {
        return Intake::query()->where('slug', strtolower($slug))->where('status', 'open')->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function present(Intake $i): array
    {
        $cohort = Cohort::query()->whereKey($i->cohort_id)->firstOrFail();
        $q = Qualification::query()->whereKey($cohort->qualification_id)->first();
        $centre = Centre::query()->whereKey($cohort->centre_id)->first();
        /** @var Tenant $tenant */
        $tenant = app(TenantContext::class)->runAsPlatform(fn () => Tenant::query()->whereKey($i->tenant_id)->firstOrFail());

        return $i->only(['id', 'slug', 'title', 'description', 'places', 'min_age', 'max_age', 'document_types', 'questions', 'status']) + [
            'accepting' => $i->accepting(),
            'opens_on' => $i->opens_on->toDateString(), 'closes_on' => $i->closes_on->toDateString(),
            'provider' => $tenant->trading_name,
            'qualification' => $q ? ['title' => $q->title, 'saqa_id' => $q->saqa_id, 'nqf_level' => $q->nqf_level, 'credits' => $q->credits] : null,
            'centre' => $centre ? ['name' => $centre->name, 'city' => $centre->city, 'province' => $centre->province] : null,
            'mode' => $cohort->mode, 'starts_on' => $cohort->intake_on->toDateString(), 'ends_on' => $cohort->planned_end_on->toDateString(),
        ];
    }
}
