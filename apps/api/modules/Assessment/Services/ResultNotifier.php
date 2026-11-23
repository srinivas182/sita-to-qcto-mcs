<?php

declare(strict_types=1);

namespace Modules\Assessment\Services;

use Modules\Assessment\Models\Result;
use Modules\Identity\Models\Device;
use Modules\Identity\Push\PushService;
use Modules\Qualifications\Models\QualificationModule;
use Throwable;

/** Tells the learner about results, on their phones. */
final class ResultNotifier
{
    public function __construct(private readonly PushService $pushes) {}

    public function decided(Result $r, QualificationModule $m): void
    {
        $this->tell($r->user_id, $r->outcome === 'competent' ? 'Competent' : 'Not yet competent', $r->outcome === 'competent'
            ? "You were found competent in {$m->code}."
            : "{$m->code}: not yet competent. Read the feedback and what to work on before your next attempt.", ['type' => 'result', 'result_id' => $r->id]);
    }

    /** @param  array<string, string>  $data */
    public function tell(string $userId, string $title, string $body, array $data): void
    {
        foreach (Device::query()->where('user_id', $userId)->whereNull('revoked_at')->whereNotNull('push_token')->get() as $d) {
            try {
                $this->pushes->send($d, $title, $body, $data);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
