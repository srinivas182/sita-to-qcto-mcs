<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Identity\Support\PhoneNumber;
use Modules\Identity\Support\SaIdNumber;

it('accepts valid South African ID numbers and reads the date of birth', function (string $id, string $dob, int $citizenship): void {
    $parsed = SaIdNumber::tryParse($id, CarbonImmutable::create(2026, 11, 22));

    expect($parsed)->not->toBeNull()
        ->and($parsed?->dateOfBirth->toDateString())->toBe($dob)
        ->and($parsed?->citizenship)->toBe($citizenship);
})->with([
    ['8001015009087', '1980-01-01', 0],
    ['800101 5009 087', '1980-01-01', 0],
    ['0501016009083', '2005-01-01', 0],
    ['9202204720182', '1992-02-20', 1],
]);

it('rejects invalid ID numbers', function (string $id): void {
    expect(SaIdNumber::isValid($id))->toBeFalse();
})->with([
    'bad checksum' => '8001015009088',
    'too short' => '800101500908',
    'bad month' => '8013015009081',
    'bad day' => '8002305009087',
    'citizenship 3' => '8001015009385',
    'letters' => '80010150090AB',
]);

it('normalises phone numbers to E.164', function (string $input, ?string $expected): void {
    expect(PhoneNumber::normalise($input))->toBe($expected);
})->with([
    ['082 123 4567', '+27821234567'],
    ['+27 82 123 4567', '+27821234567'],
    ['0027821234567', '+27821234567'],
    ['27821234567', '+27821234567'],
    ['+44 7700 900123', '+447700900123'],
    ['0921234567', null],
    ['12345', null],
    ['not a phone', null],
]);

it('masks phone numbers for display', function (): void {
    expect(PhoneNumber::mask('+27821234567'))->toBe('+2782•••••67');
});
