<?php

declare(strict_types=1);

use App\Contracts\Retainable;
use App\Models\Concerns\DeIdentifiable;
use App\Support\Anonymiser;
use App\Support\DisclosureControl;
use App\ValueObjects\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The de-identification boundary
|--------------------------------------------------------------------------
|
| Act 843 treats a person as identifiable from the retained data itself OR from
| that data combined with other information the Foundation holds. So these tests
| exist to prove two things:
|
|   1. everything on the destroy side actually goes, and
|   2. everything on the keep side is coarse enough that a small, sensitive
|      population cannot be singled out of it.
|
| The second is the part that is easy to get wrong, because the data looks
| anonymous while still describing exactly one person.
|
*/

/**
 * A stand-in beneficiary record.
 *
 * A real table rather than a mock: `deIdentify()` reads column nullability from
 * the schema to decide between nulling and overwriting, and a mock cannot
 * exercise that decision.
 */
class PrivacyTestSubject extends Model implements Retainable
{
    use DeIdentifiable;

    protected $table = 'privacy_test_subjects';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['closed_at' => 'datetime', 'date_of_birth' => 'date'];
    }

    public static function privacyElements(): array
    {
        return [
            'full_name' => 'name',
            'phone' => 'phone',
            'ghana_card' => 'national_id',
            'date_of_birth' => 'date_of_birth',
            'community' => 'community',
            'case_notes' => 'case_notes',
            'assistance_minor' => 'assistance_amount',
            'closed_at' => 'assistance_date',
            'district' => 'district',
        ];
    }

    public function retentionClass(): string
    {
        return 'beneficiary_case_record';
    }

    public function retentionAnchorDate(): ?Carbon
    {
        return $this->closed_at;
    }

    public function retentionScopeKey(): ?string
    {
        return null;
    }
}

/**
 * The same table with an incomplete map — one column left unclassified.
 *
 * Used to prove the completeness check actually catches an omission, without
 * running DDL inside a test.
 */
class PrivacyTestUnmapped extends PrivacyTestSubject
{
    public static function privacyElements(): array
    {
        $elements = parent::privacyElements();
        unset($elements['case_notes']);

        return $elements;
    }
}

beforeEach(function () {
    // `privacy_test_subjects` is built by a migration under
    // tests/database/migrations, loaded only in the testing environment. See
    // that file for why it is not created here.
    $this->anonymiser = app(Anonymiser::class);
    $this->disclosure = app(DisclosureControl::class);
});

function privacySubject(array $overrides = []): PrivacyTestSubject
{
    return PrivacyTestSubject::create(array_merge([
        'full_name' => 'Ama Serwaa Boateng',
        'phone' => '+233241234567',
        'ghana_card' => 'GHA-123456789-0',
        'date_of_birth' => '1990-04-11',
        'community' => 'Asokwa',
        'case_notes' => 'Referred by the parish; school fees arrears for two terms.',
        'assistance_minor' => 473500,
        'district' => 'Tamale Metropolitan',
        'closed_at' => now()->subYears(7),
    ], $overrides));
}

// ═══════════════════════════════════════════════════════════════════════════
//  The policy itself
// ═══════════════════════════════════════════════════════════════════════════

it('classifies every privacy element as destroy, generalise or keep', function () {
    foreach (config('compliance.privacy.elements') as $key => $element) {
        expect($element)->toHaveKeys(['label', 'disposition'], "element [{$key}]")
            ->and($element['disposition'])->toBeIn(['destroy', 'generalise', 'keep'], "element [{$key}]");
    }
});

it('refuses to act on an element nobody classified', function () {
    // The failure mode this guards: a field added later that no one decided
    // about would otherwise default to surviving de-identification.
    $this->anonymiser->disposition('invented_element');
})->throws(RuntimeException::class);

it('destroys every direct identifier the specification lists', function (string $element) {
    expect($this->anonymiser->mustDestroy($element))->toBeTrue("[{$element}] must be destroyed");
})->with([
    'name', 'phone', 'email', 'national_id', 'id_document', 'date_of_birth',
    'address', 'geolocation', 'community', 'likeness', 'signature',
    'bank_details', 'next_of_kin', 'household', 'medical', 'religion',
    'school_employer', 'narrative', 'case_notes', 'supporting_document',
    'device', 'case_reference', 'payment_reference',
]);

it('destroys the case reference and the payment reference, not merely the name', function () {
    // Both are linkages back to the person. A reference kept "for traceability"
    // is exactly what makes the rest pseudonymous rather than anonymous.
    expect($this->anonymiser->mustDestroy('case_reference'))->toBeTrue()
        ->and($this->anonymiser->mustDestroy('payment_reference'))->toBeTrue();
});

it('does not permit a reversible pseudonym to stand in for anonymisation', function () {
    expect(config('compliance.privacy.allow_reversible_pseudonyms_post_retention'))->toBeFalse();
});

// ═══════════════════════════════════════════════════════════════════════════
//  Generalisation — the part that is easy to get wrong
// ═══════════════════════════════════════════════════════════════════════════

it('bands an age rather than keeping it exact', function (int $age, string $band) {
    expect($this->anonymiser->ageBand($age))->toBe($band);
})->with([
    [0, '0-5'], [5, '0-5'], [6, '6-12'], [17, '13-17'],
    [18, '18-24'], [30, '25-34'], [64, '55-64'], [90, '65+'],
]);

it('bands an age from a date of birth', function () {
    expect($this->anonymiser->ageBand(Carbon::parse('2010-01-01'), Carbon::parse('2026-06-01')))
        ->toBe('13-17');
});

it('returns no band rather than inventing one for an unknown age', function () {
    expect($this->anonymiser->ageBand(null))->toBeNull()
        ->and($this->anonymiser->ageBand(-3))->toBeNull();
});

it('bands an assistance amount rather than publishing the exact figure', function () {
    // GHS 4,735 in a small programme identifies one person; "GHS 2,500 - 5,000"
    // does not.
    expect($this->anonymiser->amountBand(Money::ofMajor('4735.00')))
        ->toBe('2,500.00 - 5,000.00');
});

it('bands the top of the range as open-ended', function () {
    expect($this->anonymiser->amountBand(Money::ofMajor('25000.00')))
        ->toBe('Above 10,000.00');
});

it('reduces an exact date to a period', function () {
    $date = Carbon::parse('2026-03-13');

    expect($this->anonymiser->period($date))->toBe('2026-03')
        ->and($this->anonymiser->period($date, 'quarter'))->toBe('2026-Q1')
        ->and($this->anonymiser->period($date, 'year'))->toBe('2026');
});

it('rejects a granularity it does not understand', function () {
    $this->anonymiser->period(now(), 'fortnight');
})->throws(InvalidArgumentException::class);

it('permits region and district but not community or address', function () {
    expect($this->anonymiser->geographyPermitted('region'))->toBeTrue()
        ->and($this->anonymiser->geographyPermitted('district'))->toBeTrue()
        ->and($this->anonymiser->geographyPermitted('community'))->toBeFalse()
        ->and($this->anonymiser->geographyPermitted('address'))->toBeFalse();
});

it('refuses to generalise an element that should be destroyed outright', function () {
    // Calling generalise() on a name would quietly keep it. Better to explode.
    $this->anonymiser->generalise('name', 'Ama Serwaa Boateng');
})->throws(RuntimeException::class);

// ═══════════════════════════════════════════════════════════════════════════
//  Disclosure control — small groups in sensitive categories
// ═══════════════════════════════════════════════════════════════════════════

it('suppresses a statistical cell covering fewer than the minimum group', function () {
    expect($this->disclosure->count(1))->toBeNull()
        ->and($this->disclosure->count(4))->toBeNull()
        ->and($this->disclosure->count(5))->toBe(5)
        ->and($this->disclosure->count(120))->toBe(120);
});

it('publishes a zero, which identifies nobody', function () {
    expect($this->disclosure->count(0))->toBe(0);
});

it('nulls every measure on a suppressed row, not just the count', function () {
    // A row reading "beneficiaries: suppressed, total assistance: GHS 4,735"
    // has disclosed the individual amount anyway.
    $rows = collect([
        ['district' => 'Tamale Metropolitan', 'count' => 2, 'total_minor' => 473500],
        ['district' => 'Kumasi Metropolitan', 'count' => 41, 'total_minor' => 8900000],
    ]);

    $result = $this->disclosure->apply($rows, 'count', ['total_minor']);

    expect($result[0]['count'])->toBeNull()
        ->and($result[0]['total_minor'])->toBeNull()
        ->and($result[0]['suppressed'])->toBeTrue()
        ->and($result[1]['count'])->toBe(41)
        ->and($result[1]['total_minor'])->toBe(8900000)
        ->and($result[1]['suppressed'])->toBeFalse();
});

it('reports a breakdown as unpublishable when any cell is too small', function () {
    $safe = collect([['count' => 12], ['count' => 8]]);
    $unsafe = collect([['count' => 12], ['count' => 3]]);

    expect($this->disclosure->isPublishable($safe))->toBeTrue()
        ->and($this->disclosure->isPublishable($unsafe))->toBeFalse();
});

// ═══════════════════════════════════════════════════════════════════════════
//  De-identifying an actual record
// ═══════════════════════════════════════════════════════════════════════════

it('clears every destroy column and leaves the statistical ones alone', function () {
    $subject = privacySubject();

    $subject->deIdentify();
    $subject->refresh();

    expect($subject->phone)->toBeNull()
        ->and($subject->ghana_card)->toBeNull()
        ->and($subject->date_of_birth)->toBeNull()
        ->and($subject->community)->toBeNull()
        ->and($subject->case_notes)->toBeNull()
        // Kept: the district is coarse enough, and the amount and date belong
        // to the analytics projection rather than being coarsened in place.
        ->and($subject->district)->toBe('Tamale Metropolitan')
        ->and($subject->assistance_minor)->toBe(473500);
});

it('overwrites a NOT NULL column instead of leaving the original', function () {
    $subject = privacySubject();

    $subject->deIdentify();
    $subject->refresh();

    // full_name cannot be nulled, so it must not still say "Ama Serwaa Boateng".
    expect($subject->full_name)->not->toContain('Ama')
        ->and($subject->full_name)->toStartWith('[redacted:');
});

it('is safe to run twice, so a failed run can be repeated', function () {
    $subject = privacySubject();

    $subject->deIdentify();
    $first = $subject->fresh()->full_name;

    $subject->fresh()->deIdentify();

    // Already-cleared columns are skipped, so the marker does not churn.
    expect($subject->fresh()->full_name)->toBe($first);
});

it('takes the audit digest before destruction and cannot reproduce it after', function () {
    $subject = privacySubject();

    $before = $subject->retentionDigest();
    $subject->deIdentify();
    $after = $subject->fresh()->retentionDigest();

    expect($before)->toBeString()
        ->and(strlen($before))->toBe(64)
        // The identifiers are gone, so the same digest can no longer be derived
        // from the record — which is what makes the destruction real.
        ->and($after)->not->toBe($before);
});

it('salts the digest so it cannot be reproduced from outside the application', function () {
    // An unsalted hash of a Ghana Card number is reversible by anyone who knows
    // the format, which is a public fact.
    $subject = privacySubject();

    expect($subject->retentionDigest())->not->toBe(hash('sha256', 'GHA-123456789-0'));
});

it('fails the build when a column has not been classified', function () {
    expect(PrivacyTestSubject::unclassifiedColumns())->toBe([]);

    /*
     * PrivacyTestUnmapped sits on the same table but leaves `case_notes` out of
     * its map — standing in for the field somebody adds in two years and never
     * classifies. That is the real risk: not a wrong decision about a column,
     * but no decision at all, which would let an identifier survive
     * de-identification untouched.
     */
    expect(PrivacyTestUnmapped::unclassifiedColumns())->toBe(['case_notes']);
});
