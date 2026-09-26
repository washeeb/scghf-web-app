<?php

declare(strict_types=1);

use App\ValueObjects\Money;

// ── Construction ─────────────────────────────────────────────────────────────

it('stores minor units exactly', function () {
    expect(Money::ofMinor(5000)->minor)->toBe(5000)
        ->and(Money::ofMinor(5000)->currency)->toBe('GHS');
});

it('converts major units to minor units', function (string|int|float $major, int $expected) {
    expect(Money::ofMajor($major)->minor)->toBe($expected);
})->with([
    ['50', 5000],
    ['50.00', 5000],
    ['0.01', 1],
    ['0.1', 10],
    ['1234.56', 123456],
    ['1,234.56', 123456],
    [50, 5000],
    ['-25.50', -2550],
    ['0', 0],
    ['100000', 10000000],
]);

it('refuses more decimal places than the currency allows', function () {
    Money::ofMajor('50.005');
})->throws(InvalidArgumentException::class);

it('refuses input that is not a number', function (string $bad) {
    Money::ofMajor($bad);
})->with(['abc', '', '50.00.00', '5 0', '--5'])->throws(InvalidArgumentException::class);

it('refuses an unsupported currency', function () {
    Money::ofMinor(100, 'XYZ');
})->throws(InvalidArgumentException::class);

it('handles a zero-decimal currency', function () {
    expect(Money::ofMajor('500', 'XOF')->minor)->toBe(500)
        ->and(Money::ofMinor(500, 'XOF')->toMajorString())->toBe('500');
});

// ── The float problem this class exists to prevent ───────────────────────────

it('does not lose a pesewa to binary floating point', function () {
    // 0.1 + 0.2 !== 0.3 in float arithmetic. In pesewas it is exact.
    $a = Money::ofMajor('0.10');
    $b = Money::ofMajor('0.20');

    expect($a->plus($b)->minor)->toBe(30)
        ->and($a->plus($b)->toMajorString())->toBe('0.30');
});

it('survives a thousand additions without drift', function () {
    $total = Money::zero();

    for ($i = 0; $i < 1000; $i++) {
        $total = $total->plus(Money::ofMajor('0.07'));
    }

    // 1000 x 7 pesewas = 7000 pesewas = GH₵ 70.00, exactly.
    expect($total->minor)->toBe(7000)
        ->and($total->toMajorString())->toBe('70.00');
});

it('accepts a float only through a rounding boundary, never a raw binary value', function () {
    expect(Money::ofMajor(0.1 + 0.2)->minor)->toBe(30);
});

// ── Arithmetic ───────────────────────────────────────────────────────────────

it('adds, subtracts and multiplies', function () {
    $fifty = Money::ofMajor('50.00');
    $ten = Money::ofMajor('10.00');

    expect($fifty->plus($ten)->minor)->toBe(6000)
        ->and($fifty->minus($ten)->minor)->toBe(4000)
        ->and($ten->times(3)->minor)->toBe(3000);
});

it('refuses to combine different currencies', function () {
    Money::ofMinor(100, 'GHS')->plus(Money::ofMinor(100, 'USD'));
})->throws(InvalidArgumentException::class);

it('calculates a percentage without floats', function () {
    // Paystack Ghana: 1.95% of GH₵ 100.00 = GH₵ 1.95
    expect(Money::ofMajor('100.00')->percentage('1.95')->minor)->toBe(195);
});

it('rounds a percentage half-up to the nearest pesewa', function () {
    // 1.95% of GH₵ 10.03 = 19.5585 pesewas -> 20
    expect(Money::ofMajor('10.03')->percentage('1.95')->minor)->toBe(20);
});

it('applies a fee cap with min()', function () {
    // 1.95% of GH₵ 10,000 is GH₵ 195, but the cap is GH₵ 100.
    $fee = Money::ofMajor('10000.00')->percentage('1.95');
    $cap = Money::ofMajor('100.00');

    expect($fee->min($cap)->toMajorString())->toBe('100.00');
});

// ── Allocation — the parts must sum back exactly ─────────────────────────────

it('splits evenly without losing a pesewa', function (int $minor, int $parts) {
    $split = Money::ofMinor($minor)->allocateEvenly($parts);

    expect($split)->toHaveCount($parts)
        ->and(array_sum(array_map(fn (Money $m) => $m->minor, $split)))->toBe($minor);
})->with([
    [1000, 3],   // 3.34 + 3.33 + 3.33
    [1, 3],      // 0.01 + 0 + 0
    [100, 7],
    [5000, 2],
    [-1000, 3],  // negatives reconcile too, for refunds
]);

it('splits by ratio without losing a pesewa', function () {
    // A GH₵ 100 gift designated 50/30/20 across three divisions.
    $parts = Money::ofMajor('100.00')->allocate([50, 30, 20]);

    expect(array_map(fn (Money $m) => $m->toMajorString(), $parts))
        ->toBe(['50.00', '30.00', '20.00'])
        ->and(array_sum(array_map(fn (Money $m) => $m->minor, $parts)))->toBe(10000);
});

it('gives the rounding remainder away rather than dropping it', function () {
    // GH₵ 0.10 split three ways cannot divide evenly.
    $parts = Money::ofMinor(10)->allocate([1, 1, 1]);

    expect(array_map(fn (Money $m) => $m->minor, $parts))->toBe([4, 3, 3])
        ->and(array_sum(array_map(fn (Money $m) => $m->minor, $parts)))->toBe(10);
});

// ── Comparison ───────────────────────────────────────────────────────────────

it('compares amounts', function () {
    $a = Money::ofMajor('50.00');
    $b = Money::ofMajor('100.00');

    expect($a->lessThan($b))->toBeTrue()
        ->and($b->greaterThan($a))->toBeTrue()
        ->and($a->equals(Money::ofMinor(5000)))->toBeTrue()
        ->and($a->equals(Money::ofMinor(5000, 'USD')))->toBeFalse()
        ->and(Money::zero()->isZero())->toBeTrue()
        ->and($a->isPositive())->toBeTrue()
        ->and(Money::ofMinor(-1)->isNegative())->toBeTrue();
});

// ── Display ──────────────────────────────────────────────────────────────────

it('formats as GH₵ with thousands separators', function (int $minor, string $expected) {
    expect(Money::ofMinor($minor)->format())->toBe($expected);
})->with([
    [5000, 'GH₵ 50.00'],
    [123456, 'GH₵ 1,234.56'],
    [100000000, 'GH₵ 1,000,000.00'],
    [1, 'GH₵ 0.01'],
    [0, 'GH₵ 0.00'],
    [-2550, 'GH₵ -25.50'],
    [999, 'GH₵ 9.99'],
]);

it('renders the major amount as a string, never a float', function () {
    expect(Money::ofMinor(123456)->toMajorString())->toBe('1234.56')
        ->and(Money::ofMinor(1)->toMajorString())->toBe('0.01')
        ->and(Money::ofMinor(0)->toMajorString())->toBe('0.00');
});

it('round-trips through major and back', function (int $minor) {
    expect(Money::ofMajor(Money::ofMinor($minor)->toMajorString())->minor)->toBe($minor);
})->with([0, 1, 99, 100, 5000, 123456, 10000000, -2550]);

it('serialises to json with the minor amount intact', function () {
    expect(Money::ofMinor(123456)->jsonSerialize())->toBe([
        'minor' => 123456,
        'currency' => 'GHS',
        'formatted' => 'GH₵ 1,234.56',
    ]);
});

it('is immutable', function () {
    $original = Money::ofMajor('50.00');
    $original->plus(Money::ofMajor('10.00'));

    expect($original->minor)->toBe(5000);
});
