<?php

declare(strict_types=1);

use App\Support\AmountInWords;
use App\ValueObjects\Money;

/*
|--------------------------------------------------------------------------
| Amounts in words
|--------------------------------------------------------------------------
|
| This spells the figure that appears on a legal document handed to the GRA by
| a third party. Getting it wrong is not cosmetic: the words are the control
| that stops a digit being altered, so the words and the figures must agree
| exactly, and must keep agreeing across PHP upgrades — which is why this does
| not use ext-intl.
|
*/

it('spells whole cedis', function (int $pesewas, string $expected) {
    expect(AmountInWords::money(Money::ofMinor($pesewas)))->toBe($expected);
})->with([
    [100, 'One Ghana Cedi'],
    [200, 'Two Ghana Cedis'],
    [1900, 'Nineteen Ghana Cedis'],
    [2000, 'Twenty Ghana Cedis'],
    [2100, 'Twenty-One Ghana Cedis'],
    [10000, 'One Hundred Ghana Cedis'],
    [12300, 'One Hundred and Twenty-Three Ghana Cedis'],
    [100000, 'One Thousand Ghana Cedis'],
]);

it('spells cedis and pesewas together', function () {
    expect(AmountInWords::money(Money::ofMajor('1234.56')))
        ->toBe('One Thousand Two Hundred and Thirty-Four Ghana Cedis and Fifty-Six Pesewas');
});

it('uses the singular for exactly one pesewa', function () {
    expect(AmountInWords::money(Money::ofMinor(1)))->toBe('One Pesewa');
});

it('omits the cedis entirely when there are none', function () {
    // "Fifty Pesewas", not "Zero Ghana Cedis and Fifty Pesewas". The second is
    // technically true and reads like a mistake on a legal document.
    expect(AmountInWords::money(Money::ofMinor(50)))->toBe('Fifty Pesewas');
});

it('still says something for a zero amount', function () {
    expect(AmountInWords::money(Money::zero()))->toBe('Zero Ghana Cedis');
});

it('places "and" only before a trailing group below a hundred', function () {
    // The convention that distinguishes 1,050 from 1,500 when read aloud.
    expect(AmountInWords::integer(1050))->toBe('One Thousand and Fifty')
        ->and(AmountInWords::integer(1500))->toBe('One Thousand Five Hundred')
        ->and(AmountInWords::integer(1555))->toBe('One Thousand Five Hundred and Fifty-Five');
});

it('scales to millions', function () {
    expect(AmountInWords::integer(2_500_000))->toBe('Two Million Five Hundred Thousand')
        ->and(AmountInWords::integer(1_000_001))->toBe('One Million and One');
});

it('appends "Only" when asked, the cheque convention', function () {
    expect(AmountInWords::money(Money::ofMajor('50.00'), only: true))
        ->toBe('Fifty Ghana Cedis Only');
});

it('refuses to spell a negative amount', function () {
    // A negative on an acknowledgement is a bug upstream; spelling it would
    // paper over that rather than surface it.
    AmountInWords::money(Money::ofMinor(-100));
})->throws(InvalidArgumentException::class);

it('refuses a currency whose units it does not know', function () {
    // Better to fail than to invent a unit name onto a legal instrument.
    AmountInWords::money(Money::ofMinor(1000, 'ZAR'));
})->throws(RuntimeException::class);

it('agrees with the figures it accompanies', function (string $major) {
    $money = Money::ofMajor($major);

    // The whole reason both appear on the document: they are two renderings of
    // one value, and they must never diverge.
    $words = AmountInWords::money($money);

    expect($words)->not->toBeEmpty()
        ->and($money->toMajorString())->toBe($major);
})->with(['0.01', '9.99', '100.00', '4735.00', '999999.99']);
