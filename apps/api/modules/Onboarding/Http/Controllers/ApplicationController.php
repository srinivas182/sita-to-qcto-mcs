<?php

declare(strict_types=1);

namespace Modules\Onboarding\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Files\Models\File;
use Modules\Files\Services\FileService;
use Modules\Onboarding\Models\Application;
use Modules\Onboarding\Models\ApplicationFile;
use Modules\Onboarding\Services\ApplicationService;
use Modules\Tenancy\Context\TenantContext;

/**
 * Public application to join, on the platform's own site. The applicant holds a
 * resume token (X-Application-Token), and can get a new one by email code.
 */
final class ApplicationController
{
    public function start(Request $request): JsonResponse
    {
        $this->platformSite();

        // Bots fill every field; people never see this one.
        if (filled($request->input('company_fax'))) {
            return response()->json(['id' => (string) Str::ulid(), 'token' => Str::random(48)], 201);
        }

        ['application' => $application, 'token' => $token] = $this->service()->start($request->all(), $request->ip());

        return response()->json(['id' => $application->id, 'token' => $token, 'data' => $this->service()->present($application)], 201);
    }

    public function show(Request $request, string $application): JsonResponse
    {
        return response()->json(['data' => $this->service()->present($this->find($request, $application))]);
    }

    public function update(Request $request, string $application): JsonResponse
    {
        $model = $this->service()->update($this->find($request, $application), $request->except(['email', 'status']));

        return response()->json(['data' => $this->service()->present($model)]);
    }

    public function resendCode(Request $request, string $application): JsonResponse
    {
        $this->service()->sendEmailCode($this->find($request, $application));

        return response()->json(['sent' => true]);
    }

    public function verifyEmail(Request $request, string $application): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:10']]);
        $model = $this->service()->verifyEmail($this->find($request, $application), $data['code']);

        return response()->json(['data' => $this->service()->present($model)]);
    }

    public function consent(Request $request, string $application): JsonResponse
    {
        $request->validate(['accept' => ['accepted']]);
        $model = $this->service()->consent($this->find($request, $application));

        return response()->json(['data' => $this->service()->present($model)]);
    }

    public function attach(Request $request, string $application): JsonResponse
    {
        $model = $this->find($request, $application);
        $data = $request->validate([
            'kind' => ['required', 'in:accreditation_letter,cipc_certificate,other'],
            'name' => ['required', 'string', 'max:255'],
            'mime' => ['required', 'string', 'max:100'],
            'size' => ['required', 'integer', 'min:1'],
        ]);
        /** @var array{kind: string, name: string, mime: string, size: int} $data */
        ['file' => $file, 'upload' => $upload] = $this->service()->attach($model, $data);

        return response()->json([
            'file' => ['id' => $file->id, 'status' => $file->status->value],
            'upload' => $upload?->toArray() ?? [
                'method' => 'PUT',
                'url' => url("/v1/applications/{$model->id}/files/{$file->id}/content"),
                'headers' => (object) ['Content-Type' => $file->mime],
                'via' => 'api',
            ],
        ], 201);
    }

    public function upload(Request $request, string $application, string $file): JsonResponse
    {
        $model = $this->ownFile($this->find($request, $application), $file);
        $this->context()->runAsPlatform(fn () => $this->files()->receive($model, $request->getContent(true)));

        return response()->json(['uploaded' => true]);
    }

    public function complete(Request $request, string $application, string $file): JsonResponse
    {
        $app = $this->find($request, $application);
        $model = $this->ownFile($app, $file);
        $this->context()->runAsPlatform(fn () => $this->files()->complete($model));

        return response()->json(['data' => $this->service()->present($app)]);
    }

    public function detach(Request $request, string $application, string $file): JsonResponse
    {
        $app = $this->find($request, $application);
        if (! $app->editable()) {
            throw ValidationException::withMessages(['status' => 'This application can no longer be changed.']);
        }
        $model = $this->ownFile($app, $file);
        $this->context()->runAsPlatform(function () use ($model, $app): void {
            ApplicationFile::query()->where('application_id', $app->id)->where('file_id', $model->id)->delete();
            $this->files()->delete($model);
        });

        return response()->json(['data' => $this->service()->present($app)]);
    }

    public function submit(Request $request, string $application): JsonResponse
    {
        $model = $this->service()->submit($this->find($request, $application));

        return response()->json(['data' => $this->service()->present($model)]);
    }

    /** Live check of a web address while typing. */
    public function codeAvailable(Request $request): JsonResponse
    {
        $this->platformSite();
        $code = strtolower(trim((string) $request->query('code', '')));
        $probe = new Application(['preferred_code' => $code]);
        $probe->id = (string) Str::ulid();
        $problem = $this->service()->codeProblem($probe);

        return response()->json(['code' => $code, 'available' => $problem === null, 'message' => $problem, 'host' => $code.'.'.config('platform.base_domain')]);
    }

    /** Lost the resume token: get a code by email. Always answers the same way. */
    public function resume(Request $request): JsonResponse
    {
        $this->platformSite();
        $data = $request->validate(['reference' => ['required', 'string', 'max:20'], 'email' => ['required', 'email:rfc']]);
        $application = $this->byReference($data['reference'], $data['email']);

        if ($application !== null) {
            $this->service()->sendEmailCode($application);
        }

        return response()->json(['sent' => true], 202);
    }

    public function resumeVerify(Request $request): JsonResponse
    {
        $this->platformSite();
        $data = $request->validate(['reference' => ['required', 'string', 'max:20'], 'email' => ['required', 'email:rfc'], 'code' => ['required', 'string', 'max:10']]);
        $application = $this->byReference($data['reference'], $data['email'])
            ?? throw ValidationException::withMessages(['code' => 'That code is not right or has expired. Ask for a new one.']);

        // Reuses the email-code check; a resumed application keeps its verified status.
        $verified = $application->email_verified_at;
        $application->email_verified_at = null;
        $this->service()->verifyEmail($application, $data['code']);
        $token = Str::random(48);
        $this->context()->runAsPlatform(fn () => $application->forceFill(['token_hash' => hash('sha256', $token), 'email_verified_at' => $verified ?? now()])->save());

        return response()->json(['id' => $application->id, 'token' => $token, 'data' => $this->service()->present($application)]);
    }

    private function find(Request $request, string $id): Application
    {
        $this->platformSite();

        return $this->service()->find($id, $request->header('X-Application-Token'));
    }

    private function byReference(string $reference, string $email): ?Application
    {
        return $this->context()->runAsPlatform(fn () => Application::query()
            ->where('reference', strtoupper(trim($reference)))->where('email', strtolower(trim($email)))->first());
    }

    private function ownFile(Application $application, string $fileId): File
    {
        return $this->context()->runAsPlatform(function () use ($application, $fileId): File {
            abort_unless(ApplicationFile::query()->where('application_id', $application->id)->where('file_id', $fileId)->exists(), 404);

            return File::query()->findOrFail($fileId);
        });
    }

    /** Applications are made on the platform's own site, never inside a provider. */
    private function platformSite(): void
    {
        abort_if($this->context()->hasTenant(), 404);
    }

    private function service(): ApplicationService
    {
        return app(ApplicationService::class);
    }

    private function files(): FileService
    {
        return app(FileService::class);
    }

    private function context(): TenantContext
    {
        return app(TenantContext::class);
    }
}
