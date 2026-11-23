<?php

declare(strict_types=1);

require_once __DIR__.'/helpers.php';

use Modules\Enrolment\Qcto\QctoRules;

/** A clean enrolment-stage row (SoR not yet issued). */
function cleanRow(array $over = []): array
{
    return array_merge(['A' => 'SDP0712/24-0001', 'B' => '93627', 'C' => '9202204720083', 'D' => '', 'E' => '533', 'F' => 'BA', 'G' => 'SA', 'H' => 'Zul', 'I' => 'F',
        'J' => 'SA', 'K' => '02', 'L' => 'N', 'M' => '', 'N' => '03', 'O' => 'Dlamini', 'P' => 'Ayanda', 'Q' => '', 'R' => 'Ms', 'S' => '19920220',
        'T' => '12 Vilakazi Street', 'U' => 'Orlando West', 'V' => 'Soweto', 'W' => '12 Vilakazi Street', 'X' => 'Orlando West', 'Y' => 'Soweto', 'Z' => '1804',
        'AA' => '1804', 'AB' => '', 'AC' => '0821234567', 'AD' => '', 'AE' => 'ayanda@example.co.za', 'AF' => '7', 'AG' => '798', 'AH' => 'Yes', 'AI' => '20270110',
        'AJ' => '20271210', 'AK' => '02', 'AL' => '', 'AM' => '', 'AN' => '1', 'AO' => '', 'AP' => '', 'AQ' => '20270115'], $over);
}

function ctx(array $over = []): array
{
    return array_merge(['intake_on' => '2027-01-10', 'planned_end_on' => '2027-12-10', 'centre_expires_on' => '2029-01-01', 'in_scope' => true,
        'scope_expires_on' => null, 'eisa_on' => null], $over);
}

/** @return list<string> "code:column" of the errors */
function errorsOf(array $row, array $c = []): array
{
    return array_map(fn (array $f) => $f['code'].':'.$f['column'], array_values(array_filter(QctoRules::check($row, ctx($c), []), fn (array $f) => $f['severity'] === 'error')));
}

it('passes a clean enrolment row', function (): void {
    expect(QctoRules::check(cleanRow(), ctx(), []))->toBe([]);
});

it('checks code lists, identity, names, dates, addresses and contact details', function (): void {
    expect(errorsOf(cleanRow(['F' => 'XX', 'H' => 'isiZulu'])))->toBe(['Q-CODE:F', 'Q-CODE:H'])
        ->and(errorsOf(cleanRow(['C' => '9202204720084'])))->toContain('Q-ID-CHECK:C')
        ->and(errorsOf(cleanRow(['I' => 'M'])))->toBe(['Q-ID-GENDER:I'])
        ->and(errorsOf(cleanRow(['S' => '19920221'])))->toBe(['Q-DOB-ID:S'])
        ->and(errorsOf(cleanRow(['S' => '1992-02-20'])))->toBe(['Q-DATE:S'])
        ->and(errorsOf(cleanRow(['C' => '', 'D' => 'A1234567', 'E' => '533'])))->toBe(['Q-ID-TYPE:E'])
        ->and(errorsOf(cleanRow(['O' => 'Dlamini2'])))->toBe(['Q-NAME:O'])
        ->and(errorsOf(cleanRow(['P' => ' Ayanda'])))->toBe(['Q-SPACE:P', 'Q-NAME:P'])
        ->and(errorsOf(cleanRow(['T' => '12'])))->toBe(['Q-ADDRESS:T'])
        ->and(errorsOf(cleanRow(['T' => '12 Vilakazi St; Soweto'])))->toBe(['Q-ADDRESS:T'])
        ->and(errorsOf(cleanRow(['Z' => '180'])))->toBe(['Q-POSTCODE:Z'])
        ->and(errorsOf(cleanRow(['AC' => '+27821234567'])))->toBe(['Q-PHONE:AC'])
        ->and(errorsOf(cleanRow(['AE' => 'ayanda at example'])))->toBe(['Q-EMAIL:AE'])
        ->and(errorsOf(cleanRow(['L' => '01', 'M' => ''])))->toBe(['Q-DIS-RATING:M'])
        ->and(errorsOf(cleanRow(['M' => '02'])))->toBe(['Q-DIS-RATING:M'])
        ->and(errorsOf(cleanRow(['AH' => 'No'])))->toBe(['Q-POPI:AI'])
        ->and(errorsOf(cleanRow(['AJ' => '20261201'])))->toBe(['Q-END:AJ']);
});

it('checks the Statement of Results, readiness and foundational learning columns together', function (): void {
    $issued = ['AK' => '01', 'AL' => '20271120', 'AN' => '4', 'AM' => 'AC0099/22-0001', 'AO' => '01', 'AP' => 'FLC-123456'];
    expect(errorsOf(cleanRow($issued)))->toBe([])
        ->and(errorsOf(cleanRow(['AK' => '01', 'AN' => '4'])))->toBe(['Q-SOR:AL', 'Q-AC:AM', 'Q-FLC:AO', 'Q-FLC:AP'])
        ->and(errorsOf(cleanRow(['AL' => '20271120'])))->toBe(['Q-SOR:AL', 'Q-READY:AN'])
        ->and(errorsOf(cleanRow(['AN' => '4'])))->toBe(['Q-READY:AN'])
        ->and(errorsOf(cleanRow(array_merge($issued, ['AO' => '02', 'AP' => 'RPL2027']))))->toBe(['Q-FLC:AP'])
        ->and(errorsOf(cleanRow(array_merge($issued, ['AO' => '05', 'AP' => '']))))->toBe([])
        ->and(errorsOf(cleanRow($issued), ['eisa_on' => '2027-11-15']))->toBe(['Q-SOR-EISA:AL']);
});

it('checks the accreditation', function (): void {
    expect(errorsOf(cleanRow(), ['in_scope' => false]))->toBe(['Q-ACC-SCOPE:B'])
        ->and(errorsOf(cleanRow(), ['centre_expires_on' => '2026-12-31']))->toBe(['Q-ACC-EXPIRED:A']);
    $w = array_column(QctoRules::check(cleanRow(), ctx(['scope_expires_on' => '2027-06-30']), []), 'code');
    expect($w)->toBe(['Q-ACC-ENDS']);
});

it('refuses to export or record a file with errors, and shows the learner what to fix', function (): void {
    enrolmentSetup($this);
    $this->intake = openIntake($this, ['document_types' => [], 'min_age' => null]);
    $learner = enrolLearner($this, '082 123 4567', '9202204720083');
    signInOwner($this, $this->tym, 'qcto@tym.test');
    $this->get(TYM_V1."/cohorts/{$this->cohort->id}/qcto-enrolment/workbook?acknowledge=1")->assertStatus(422)
        ->assertJsonPath('errors.file.0', '1 learner has errors the QCTO would reject. Fix them first.');
    $this->postJson(TYM_V1."/cohorts/{$this->cohort->id}/qcto-enrolment/sent", ['sent_on' => now()->toDateString()])->assertStatus(422)->assertJsonValidationErrors('file');
    $list = $this->getJson(TYM_V1."/cohorts/{$this->cohort->id}/qcto-enrolment")->json('data');
    expect($list['rules']['version'])->toBe(QctoRules::VERSION)->and($list['errors'])->toBeGreaterThan(0)
        ->and($list['rows'][0]['errors'][0])->toHaveKeys(['code', 'severity', 'fix']);
});

it('lists the learner\'s own QCTO problems with their profile', function (): void {
    enrolmentSetup($this);
    $this->intake = openIntake($this, ['document_types' => [], 'min_age' => null]);
    $learner = enrolLearner($this, '082 123 4567', '9202204720083');
    signInOwner($this, $this->tym, 'qcto@tym.test');
    $q = $this->getJson(TYM_V1."/people/{$learner->id}/profile")->assertOk()->json('qcto.0');
    expect($q['cohort'])->toBe($this->cohort->name)->and(array_column($q['errors'], 'column'))->toContain('T')->and(array_column($q['errors'], 'fix'))->not->toContain('centre');
});
