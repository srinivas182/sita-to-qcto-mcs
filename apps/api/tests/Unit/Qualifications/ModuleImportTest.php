<?php

declare(strict_types=1);

use Modules\Qualifications\Support\ModuleImport;

it('reads a spreadsheet paste with a header row', function (): void {
    $r = ModuleImport::parse("kind\tcode\ttitle\tnqf_level\tcredits\n\t641201001-KM-01\tIndustry contexts\t4\t8\nPM\t641201001-PM-01\tSet out a building\t4\t12", false);
    expect($r['errors'])->toBe([])
        ->and($r['rows'][0])->toMatchArray(['kind' => 'KM', 'code' => '641201001-KM-01', 'title' => 'Industry contexts', 'nqf_level' => 4, 'credits' => 8, 'group' => 'compulsory'])
        ->and($r['rows'][1]['kind'])->toBe('PM');
});

it('reads loose lines copied from a curriculum document', function (): void {
    $r = ModuleImport::parse("641201001-KM-01  Industry contexts  NQF Level 04  8\n641201001-WM-03 Laying bricks on site, NQF 4, 40, elective", false);
    expect($r['errors'])->toBe([])
        ->and($r['rows'][0])->toMatchArray(['code' => '641201001-KM-01', 'title' => 'Industry contexts', 'nqf_level' => 4, 'credits' => 8])
        ->and($r['rows'][1])->toMatchArray(['kind' => 'WM', 'title' => 'Laying bricks on site', 'credits' => 40, 'group' => 'elective']);
});

it('reads legacy unit standards from the SAQA list', function (): void {
    $r = ModuleImport::parse("119073 | Build masonry superstructures using solid units | 1 | 12 | elective\n13973 | Identify and use protective clothing and equipment in general construction | 1 | 4 | core", true);
    expect($r['errors'])->toBe([])
        ->and($r['rows'][0])->toMatchArray(['kind' => 'US', 'code' => '119073', 'nqf_level' => 1, 'credits' => 12, 'group' => 'elective'])
        ->and($r['rows'][1]['group'])->toBe('core');
});

it('explains every bad line and keeps the good ones', function (): void {
    $r = ModuleImport::parse("641201001-KM-01 Industry contexts NQF 4 8\nno code here 8\n641201001-KM-01 Same code again NQF 4 8\n641201001-PM-02 Missing credits NQF 4\n119073 | Unit standard in an occupational qual | 1 | 12", false);
    expect($r['rows'])->toHaveCount(1)
        ->and(collect($r['errors'])->pluck('line')->all())->toEqualCanonicalizing([2, 3, 4, 5]);
    expect(collect($r['errors'])->firstWhere('line', 3)['message'])->toContain('already on line 1');
});
