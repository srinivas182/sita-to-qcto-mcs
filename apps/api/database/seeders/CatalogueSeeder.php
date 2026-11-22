<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Qualifications\Models\Qualification;
use Modules\Qualifications\Models\QualificationModule;
use Modules\Tenancy\Context\TenantContext;

/**
 * The first catalogue entries, from SAQA records (checked November 2026). Entries
 * without their modules stay drafts until a catalogue editor loads the modules from
 * the QCTO curriculum document: we do not guess module codes.
 * Safe to run again: existing entries are left as they are.
 */
final class CatalogueSeeder extends Seeder
{
    public function run(): void
    {
        app(TenantContext::class)->runAsPlatform(function (): void {
            // Complete: header, credit rules and all 106 unit standards.
            $getc = json_decode((string) file_get_contents(database_path('data/catalogue/49411.json')), true, flags: JSON_THROW_ON_ERROR);
            $this->entry($getc, 'published');

            $successors = [];
            foreach ([['126058', 'National Occupational Certificate: Civil Services Construction Constructor', 120],
                ['126057', 'National Occupational Certificate: Civil Road Construction Constructor', 123],
                ['126077', 'National Occupational Certificate: Civil Structures Construction Constructor', 126]] as [$id, $title, $credits]) {
                $successors[] = $this->entry(['type' => 'occupational_certificate', 'saqa_id' => $id, 'title' => $title, 'nqf_level' => 4,
                    'credits' => $credits, 'quality_partner' => 'CETA', 'notes' => 'Load the modules from the QCTO curriculum document, then publish.'], 'draft')->id;
            }

            $this->entry([
                'type' => 'legacy_qualification', 'saqa_id' => '65409', 'title' => 'National Certificate: Building and Civil Construction',
                'nqf_level' => 3, 'credits' => 140, 'quality_partner' => 'CETA', 'registration_end' => '2023-06-30',
                'last_enrolment_on' => '2028-06-30', 'last_achievement_on' => '2031-06-30', 'successor_ids' => $successors,
                'source_url' => 'https://pcqs.saqa.org.za/showQualification.php?id=65409',
                'notes' => 'Load the unit standards and credit rules from the SAQA record, then publish.',
            ], 'draft');

            $this->entry([
                'type' => 'trade', 'saqa_id' => '93627', 'title' => 'Occupational Certificate: Bricklayer', 'nqf_level' => 4, 'credits' => 361,
                'quality_partner' => 'CETA', 'assessment_partner' => 'NAMB (trade test)', 'eisa' => true, 'registration_end' => '2025-12-30',
                'last_enrolment_on' => '2026-12-30', 'last_achievement_on' => '2029-12-30',
                'source_url' => 'https://regqs.saqa.org.za/viewQualification.php?id=93627',
                'notes' => 'Reregistered (EXCO 0425/24). Load the KM/PM/WM modules and curriculum code from the QCTO curriculum document, then publish. Check whether the enrolment date is extended.',
            ], 'draft');

            $this->entry([
                'type' => 'occupational_certificate', 'saqa_id' => '120663', 'title' => "Elementary Occupational Certificate: Builder's Worker",
                'nqf_level' => 2, 'credits' => 120, 'quality_partner' => 'CETA',
                'source_url' => 'https://allqs.saqa.org.za/showQualification.php?id=120663',
                'notes' => 'Load the modules from the QCTO curriculum document, then publish.',
            ], 'draft');
        });
    }

    /** @param  array<string, mixed>  $data */
    private function entry(array $data, string $status): Qualification
    {
        $existing = Qualification::query()->where('saqa_id', $data['saqa_id'])->first();
        if ($existing !== null) {
            return $existing;
        }

        /** @var list<array<string, mixed>> $modules */
        $modules = $data['modules'] ?? [];
        unset($data['modules']);
        $q = Qualification::query()->create($data);
        foreach ($modules as $i => $m) {
            QualificationModule::query()->create($m + ['qualification_id' => $q->id, 'sort' => $i]);
        }
        if ($status === 'published') {
            $q->forceFill(['status' => 'published', 'published_at' => now()])->save();
        }

        return $q;
    }
}
