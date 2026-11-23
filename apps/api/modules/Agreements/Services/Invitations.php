<?php

declare(strict_types=1);

namespace Modules\Agreements\Services;

use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Modules\Agreements\Models\Agreement;
use Modules\Agreements\Models\Signer;
use Modules\Agreements\Notifications\AgreementMail;
use Modules\Messaging\Services\MessageService;
use Modules\Tenancy\Context\TenantContext;
use Modules\Tenancy\Models\Tenant;

/** Sends signing links. Each send makes a new link; older links stop working. */
final class Invitations
{
    public function __construct(private readonly MessageService $messages, private readonly TenantContext $context) {}

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
