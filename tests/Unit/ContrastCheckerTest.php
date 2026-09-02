<?php

declare(strict_types=1);

use App\Support\ContrastChecker;

beforeEach(fn () => $this->checker = new ContrastChecker);

it('matches the ratios computed in the blueprint', function (string $fg, string $bg, float $expected) {
    // These are the exact figures recorded in PHASE-1-BLUEPRINT.md §5.5. If this
    // test drifts, either the implementation is wrong or the document is.
    expect($this->checker->ratioRounded($fg, $bg))->toBe($expected);
})->with([
    'text-primary on white' => ['#0F1A17', '#FFFFFF', 17.79],
    'text-muted on white' => ['#5E706B', '#FFFFFF', 5.24],
    'white on brand green' => ['#FFFFFF', '#0B4D3F', 9.78],
    'ink on brand orange' => ['#3A1200', '#FC6302', 5.47],
    'teal on dark bg' => ['#2EC4A8', '#071310', 8.63],
    'dark text on dark surface' => ['#ECF5F2', '#122420', 14.56],
    'orange-300 on deep green' => ['#FF9C5C', '#0B4D3F', 4.73],
    'focus ring on white' => ['#0B7D66', '#FFFFFF', 5.07],
]);

it('reproduces the banned combinations at exactly the ratio recorded', function (string $fg, string $bg, float $ratio) {
    // §5.5 lists these as FAILING. They are asserted here so that nobody
    // "fixes" the palette by reintroducing one — the classic being white text
    // on the orange donate button, which reads fine and fails AA.
    expect($this->checker->ratioRounded($fg, $bg))->toBe($ratio)
        ->and($this->checker->passesNormalText($fg, $bg))->toBeFalse();
})->with([
    'white on brand orange' => ['#FFFFFF', '#FC6302', 3.03],
    'white on orange-600' => ['#FFFFFF', '#E14E00', 3.97],
    'white on green-600 old' => ['#FFFFFF', '#0D8770', 4.45],
    'brand green on teal' => ['#0B4D3F', '#2EC4A8', 4.46],
    'amber icon on white' => ['#F59E0B', '#FFFFFF', 2.15],
    'teal text on white' => ['#2EC4A8', '#FFFFFF', 2.19],
]);

it('returns 21:1 for black on white and 1:1 for identical colours', function () {
    expect($this->checker->ratioRounded('#000000', '#FFFFFF'))->toBe(21.0)
        ->and($this->checker->ratioRounded('#FC6302', '#FC6302'))->toBe(1.0);
});

it('is symmetric', function () {
    expect($this->checker->ratio('#0F1A17', '#FFFFFF'))
        ->toBe($this->checker->ratio('#FFFFFF', '#0F1A17'));
});

it('accepts shorthand hex and a missing hash', function () {
    expect($this->checker->ratioRounded('#000', '#FFF'))->toBe(21.0)
        ->and($this->checker->ratioRounded('000000', 'FFFFFF'))->toBe(21.0);
});

it('rejects a value that is not a hex colour', function (string $bad) {
    $this->checker->ratio($bad, '#FFFFFF');
})->with(['red', '#GGGGGG', '#12345', ''])->throws(InvalidArgumentException::class);

it('grades against the WCAG levels', function () {
    expect($this->checker->grade('#0F1A17', '#FFFFFF'))->toBe('AAA')      // 17.79
        ->and($this->checker->grade('#5E706B', '#FFFFFF'))->toBe('AA')     // 5.24
        ->and($this->checker->grade('#FC6302', '#FFFFFF'))->toBe('AA Large') // 3.03
        ->and($this->checker->grade('#2EC4A8', '#FFFFFF'))->toBe('Fail');   // 2.19
});

it('picks the more readable text colour for a background', function () {
    // The donate button: dark ink wins on orange, which is exactly why the
    // palette does not use white there.
    expect($this->checker->bestTextOn('#FC6302'))->toBe('#0F1A17')
        ->and($this->checker->bestTextOn('#0B4D3F'))->toBe('#FFFFFF');
});

it('applies the 3:1 threshold for large text and non-text', function () {
    // Brand orange on white: banned for body copy, allowed for a headline.
    expect($this->checker->passesNormalText('#FC6302', '#FFFFFF'))->toBeFalse()
        ->and($this->checker->passesLargeText('#FC6302', '#FFFFFF'))->toBeTrue()
        ->and($this->checker->passesNonText('#FC6302', '#FFFFFF'))->toBeTrue();
});
