<?php

declare(strict_types=1);

use App\Payments\FeeCalculator;
use App\ValueObjects\Money;

/*
|--------------------------------------------------------------------------
| Paystack fees, and covering them
|--------------------------------------------------------------------------
|
| Paystack Ghana: 1.95%, capped at GH₵ 100.00. Constructed explicitly here
| rather than from config, so these tests describe the arithmetic rather than
| the current rate — and keep passing when the negotiated rate changes.
|
| The property that matters is the invariant at the bottom: grossing up must
| leave the foundation with AT LEAST the intended gift, at every amount. Getting
| that wrong by a pesewa on every fee-covered donation is the kind of error
| nobody notices for a year.
|
*/

function ghanaFees(): FeeCalculator
{
    return new FeeCalculator(percentBps: 195, capMinor: 10_000);
}

it('charges the percentage below the cap', function () {
    // GH₵ 100.00 × 1.95% = GH₵ 1.95
    expect(ghanaFees()->on(Money::ofMajor('100.00')))->toEqualPesewas(195);
});

it('rounds the fee half up, to the pesewa', function () {
    // GH₵ 10.00 × 1.95% = 19.5 pesewas → 20
    expect(ghanaFees()->on(Money::ofMajor('10.00')))->toEqualPesewas(20);
});

it('stops growing at the cap', function () {
    // 1.95% of GH₵ 100,000 would be GH₵ 1,950 — the cap holds it at GH₵ 100.
    expect(ghanaFees()->on(Money::ofMajor('100000.00')))->toEqualPesewas(10_000);
});

it('reports what actually reaches the foundation', function () {
    expect(ghanaFees()->netOf(Money::ofMajor('100.00')))->toEqualPesewas(9_805);
});

it('takes nothing on nothing', function () {
    expect(ghanaFees()->on(Money::zero()))->toEqualPesewas(0);
});

// ── Grossing up ──────────────────────────────────────────────────────────────

it('grosses up so the foundation nets the intended gift', function () {
    $fees = ghanaFees();
    $intended = Money::ofMajor('100.00');

    $charge = $fees->grossUp($intended);

    // The mistake this exists to avoid: intended + fee(intended) would charge
    // GH₵ 101.95 and net GH₵ 99.96 — short, because the fee is charged on the
    // larger amount.
    expect($charge->toMinor())->toBeGreaterThan(10_195)
        ->and($fees->netOf($charge)->greaterThanOrEqual($intended))->toBeTrue();
});

it('adds exactly the cap once the fee is capped', function () {
    $fees = ghanaFees();
    $intended = Money::ofMajor('50000.00');

    // Above the cap the fee no longer grows with the charge, so grossing up is
    // simple addition.
    expect($fees->grossUp($intended))->toEqualPesewas(5_000_000 + 10_000);
});

it('quotes the donor what covering the fee will add', function () {
    $fees = ghanaFees();

    expect($fees->coverageFor(Money::ofMajor('100.00')))
        ->toEqualPesewas($fees->grossUp(Money::ofMajor('100.00'))->toMinor() - 10_000);
});

it('never leaves the foundation short, at any amount', function (string $major) {
    $fees = ghanaFees();
    $intended = Money::ofMajor($major);

    $net = $fees->netOf($fees->grossUp($intended));

    // The invariant. Off by a pesewa on every fee-covered gift is the kind of
    // error that goes unnoticed for a year.
    expect($net->greaterThanOrEqual($intended))->toBeTrue(
        "grossing up {$major} netted {$net->toMajorString()}"
    );
})->with([
    '5.00', '9.99', '10.00', '13.37', '50.00', '99.99', '100.00',
    '500.00', '1234.56', '5000.00', '5128.20', '5128.21', '10000.00', '100000.00',
]);

it('does not overshoot by more than a pesewa', function (string $major) {
    $fees = ghanaFees();
    $intended = Money::ofMajor($major);

    $overshoot = $fees->netOf($fees->grossUp($intended))->minus($intended);

    // Grossing up should find the SMALLEST sufficient charge. Rounding can
    // leave a single pesewa over; more than that means the search is loose and
    // every donor is being over-charged.
    expect($overshoot->toMinor())->toBeLessThanOrEqual(1);
})->with(['5.00', '13.37', '100.00', '1234.56', '5128.20']);

it('leaves a zero gift alone', function () {
    expect(ghanaFees()->grossUp(Money::zero()))->toEqualPesewas(0);
});

it('reports where the cap starts to bite', function () {
    // GH₵ 100 cap ÷ 1.95% ≈ GH₵ 5,128.20
    expect(ghanaFees()->capReachedAt())->toEqualPesewas(512_820);
});

it('handles a flat component if a gateway ever adds one', function () {
    $fees = new FeeCalculator(percentBps: 195, capMinor: 10_000, flatMinor: 100);

    // GH₵ 100.00 × 1.95% + GH₵ 1.00 = GH₵ 2.95
    expect($fees->on(Money::ofMajor('100.00')))->toEqualPesewas(295)
        ->and($fees->netOf($fees->grossUp(Money::ofMajor('100.00')))->greaterThanOrEqual(Money::ofMajor('100.00')))
        ->toBeTrue();
});

it('works with no cap at all', function () {
    $fees = new FeeCalculator(percentBps: 195, capMinor: 0);

    expect($fees->on(Money::ofMajor('100000.00')))->toEqualPesewas(195_000)
        ->and($fees->capReachedAt())->toBeNull();
});

it('refuses a rate that makes grossing up impossible', function () {
    // At 100% no charge can ever net the intended gift, and the loop that
    // searches for one would not terminate.
    new FeeCalculator(percentBps: 10_000, capMinor: 0);
})->throws(RuntimeException::class);
