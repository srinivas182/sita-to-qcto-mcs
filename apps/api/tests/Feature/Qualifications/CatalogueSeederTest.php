<?php

declare(strict_types=1);

use Database\Seeders\CatalogueSeeder;
use Modules\Qualifications\Models\Qualification;
use Modules\Qualifications\Services\CatalogueService;

it('seeds a complete, valid GETC: Construction and drafts the rest without guessing modules', function (): void {
    $this->seed(CatalogueSeeder::class);
    $this->seed(CatalogueSeeder::class); // safe to run again

    asPlatform(function (): void {
        $getc = Qualification::query()->where('saqa_id', '49411')->sole();
        $checks = app(CatalogueService::class)->checks($getc);
        expect($getc->status)->toBe('published')
            ->and($getc->modules()->count())->toBe(106)
            ->and($checks['errors'])->toBe([])
            ->and($checks['totals']['core'])->toBe(21)
            ->and($checks['totals']['fundamental'])->toBe(59)
            ->and($getc->enrolmentState(now()->toImmutable()->setDate(2026, 11, 22)))->toBe('closed');

        $bricklayer = Qualification::query()->where('saqa_id', '93627')->sole();
        expect($bricklayer->status)->toBe('draft')->and($bricklayer->modules()->count())->toBe(0)
            ->and($bricklayer->last_enrolment_on?->toDateString())->toBe('2026-12-30');
        expect(Qualification::query()->where('saqa_id', '65409')->sole()->successor_ids)->toHaveCount(3);
    });
});
