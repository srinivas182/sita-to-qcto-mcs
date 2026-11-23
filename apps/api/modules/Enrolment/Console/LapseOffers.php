<?php

declare(strict_types=1);

namespace Modules\Enrolment\Console;

use Illuminate\Console\Command;
use Modules\Enrolment\Models\Offer;
use Modules\Enrolment\Services\OfferService;
use Modules\Tenancy\Context\TenantContext;
use Modules\Tenancy\Models\Tenant;
use Throwable;

/** Hourly: offers past their date lapse, and the waiting list moves up. */
final class LapseOffers extends Command
{
    protected $signature = 'enrolment:lapse-offers';

    protected $description = 'Lapse offers that were not accepted in time and offer the places to the waiting list';

    public function handle(TenantContext $context, OfferService $offers): int
    {
        $lapsed = 0;
        foreach ($context->runAsPlatform(fn () => Tenant::query()->where('status', 'active')->get()) as $tenant) {
            $context->runAs($tenant, function () use ($offers, &$lapsed): void {
                foreach (Offer::query()->where('status', 'open')->where('expires_at', '<=', now())->get() as $offer) {
                    try {
                        $offers->lapse($offer);
                        $lapsed++;
                    } catch (Throwable $e) {
                        report($e);
                    }
                }
            });
        }
        $this->info("{$lapsed} offers lapsed");

        return self::SUCCESS;
    }
}
