<?php

declare(strict_types=1);

use App\Modules\Catalog\Tcgcsv\PrintedNumber;

test('a number with a total is split and compared without leading zeros', function () {
    $printed = PrintedNumber::parse('055/091');

    expect($printed?->number)->toBe('55')
        ->and($printed?->total)->toBe('91')
        ->and($printed?->isNumber('55'))->toBeTrue()
        ->and($printed?->isNumber('055'))->toBeTrue()
        ->and($printed?->isNumber('56'))->toBeFalse();
});

test('a bare number has no total', function () {
    $printed = PrintedNumber::parse('055');

    expect($printed?->number)->toBe('55')->and($printed?->total)->toBeNull();
});

test('lettered numbers keep their letters and compare case-insensitively', function () {
    $printed = PrintedNumber::parse('TG01/TG30');

    expect($printed?->number)->toBe('TG01')
        ->and($printed?->total)->toBe('TG30')
        ->and($printed?->isNumber('tg01'))->toBeTrue();
});

test('junk and empty values are not a printed number', function (?string $value) {
    expect(PrintedNumber::parse($value))->toBeNull();
})->with([null, '', '  ', '/', '12/', '/12', 'a b', '1/2/3']);

test('a zero number stays a zero', function () {
    expect(PrintedNumber::parse('000')?->number)->toBe('0');
});
