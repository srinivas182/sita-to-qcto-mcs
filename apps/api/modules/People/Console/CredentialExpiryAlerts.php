<?php

declare(strict_types=1);

namespace Modules\People\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Modules\Audit\AuditLogger;
use Modules\Identity\Models\User;
use Modules\People\Models\StaffCredential;
use Modules\People\Notifications\CredentialExpiring;
use Modules\Tenancy\Context\TenantContext;
use Modules\Tenancy\Models\Tenant;

/** Daily: tells the holder and the owners 90, 60 and 30 days before a registration ends, and when it ends (once each). */
final class CredentialExpiryAlerts extends Command
{
    protected $signature = 'credentials:expiry-alerts';

    protected $description = 'Email staff and owners about registrations that are ending';

    public function handle(TenantContext $context, AuditLogger $audit): int
    {
        $sent = 0;
        foreach ($context->runAsPlatform(fn () => Tenant::query()->where('status', 'active')->get()) as $tenant) {
            $context->runAs($tenant, function () use ($tenant, $audit, &$sent): void {
                $due = StaffCredential::query()->where('status', 'verified')->whereNotNull('expires_on')
                    ->whereDate('expires_on', '<=', now()->addDays(90))->whereDate('expires_on', '>=', now()->subDays(30))->get();
                if ($due->isEmpty()) {
                    return;
                }
                $owners = DB::table('role_assignments as r')->join('users as u', 'u.id', '=', 'r.user_id')
                    ->where('r.role', 'tp-owner')->whereNotNull('u.email')->distinct()->pluck('u.email')->map(fn ($e) => (string) $e);
                $people = User::query()->whereIn('id', $due->pluck('user_id'))->get()->keyBy('id');

                foreach ($due as $c) {
                    $days = (int) now()->startOfDay()->diffInDays($c->expires_on, false);
                    $threshold = match (true) {
                        $days < 0 => 0,
                        $days <= 30 => 30,
                        $days <= 60 => 60,
                        default => 90,
                    };
                    if ($c->alerted_for !== null && $c->alerted_for <= $threshold) {
                        continue;
                    }
                    $person = $people->get($c->user_id);
                    $args = [$tenant->trading_name, $tenant->code, $person?->fullName() ?? '', StaffCredential::ROLES[$c->role] ?? $c->role,
                        "{$c->body} {$c->registration_number}", (string) $c->expires_on?->format('j F Y'), $days];
                    if (is_string($person?->email)) {
                        Notification::route('mail', $person->email)->notify(new CredentialExpiring(...[...$args, true]));
                    }
                    foreach ($owners->reject(fn (string $e) => $e === $person?->email) as $email) {
                        Notification::route('mail', $email)->notify(new CredentialExpiring(...[...$args, false]));
                    }
                    $c->forceFill(['alerted_for' => $threshold])->saveQuietly();
                    $audit->record('credential.expiry_alert', $c, meta: ['days' => $days, 'threshold' => $threshold]);
                    $sent++;
                }
            });
        }
        $this->info("{$sent} alerts sent");

        return self::SUCCESS;
    }
}
