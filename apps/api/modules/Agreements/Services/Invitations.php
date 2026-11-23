<?php

declare(strict_types=1);

namespace Modules\Agreements\Services;

use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Modules\Agreements\Models\Agreement;
use Modules\Agreements\Models\Signer;
use Modules\Agreements\Notifications\AgreementMail;
use Modules\Identity\Models\Device;
use Modules\Identity\Push\PushService;
use Modules\Messaging\Services\MessageService;
use Modules\Tenancy\Context\TenantContext;
use Modules\Tenancy\Models\Tenant;
use Throwable;

/** Sends signing links. Each send makes a new link; older links stop working. */
final class Invitations
{
    public function __construct(private readonly MessageService $messages, private readonly TenantContext $context, private readonly PushService $pushes) {}

    public function sendAll(Agreement $agreement): void
    {
        foreach ($agreement->signers()->where('status', 'pending')->get() as $signer) {
            $this->send($signer, $agreement);
        }
    }

    /** @param  'invite'|'reminder'  $kind */
    public function send(Signer $signer, Agreement $agreement, string $kind = 'invite'): void
    {
        $token = Str::random(48);
        $signer->forceFill(['token_hash' => hash('sha256', $token)])->save();
        $tenant = $this->tenant($agreement);
        $url = self::signUrl($tenant, $token);

        if ($signer->phone !== null) {
            $text = $kind === 'reminder'
                ? "{$tenant->trading_name}: a reminder to sign \"{$agreement->title}\". {$url}"
                : "{$tenant->trading_name} has sent you \"{$agreement->title}\" to sign. Read and sign: {$url}";
            $this->messages->notify($signer->phone, $text, 'agreement');
        }
        if ($signer->email !== null) {
            Notification::route('mail', $signer->email)->notify(new AgreementMail($kind, $tenant->trading_name, $agreement->title, $signer->name, $url));
        }
        if ($signer->user_id !== null) {
            $this->push($signer->user_id, $kind === 'reminder' ? 'Reminder: a document to sign' : 'You have a document to sign',
                "{$tenant->trading_name}: {$agreement->title}", ['type' => 'agreement', 'signer_id' => $signer->id]);
        }
    }

    /** @param  array<string, string>  $data */
    private function push(string $userId, string $title, string $body, array $data): void
    {
        foreach (Device::query()->where('user_id', $userId)->whereNull('revoked_at')->whereNotNull('push_token')->get() as $device) {
            try {
                $this->pushes->send($device, $title, $body, $data);
            } catch (Throwable $e) {
                report($e); // a push is a courtesy: the link has gone by message already
            }
        }
    }

    public function completed(Agreement $agreement): void
    {
        $tenant = $this->tenant($agreement);
        foreach ($agreement->signers()->get() as $signer) {
            // A fresh link to the signer's own page, where the signed copy can be downloaded.
            $token = Str::random(48);
            $signer->forceFill(['token_hash' => hash('sha256', $token)])->save();
            $url = self::signUrl($tenant, $token);
            if ($signer->email !== null) {
                Notification::route('mail', $signer->email)->notify(new AgreementMail('completed', $tenant->trading_name, $agreement->title, $signer->name, $url));
            } elseif ($signer->phone !== null) {
                $this->messages->notify($signer->phone, "{$tenant->trading_name}: \"{$agreement->title}\" is signed by everyone. Your copy: {$url}", 'agreement');
            }
        }
    }

    public static function signUrl(Tenant $tenant, string $token): string
    {
        return 'https://'.$tenant->code.'.'.config('platform.base_domain').'/sign/'.$token;
    }

    private function tenant(Agreement $agreement): Tenant
    {
        /** @var Tenant */
        return $this->context->runAsPlatform(fn () => Tenant::query()->findOrFail($agreement->tenant_id));
    }
}
