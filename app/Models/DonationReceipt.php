<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\ValueObjects\Money;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * An issued acknowledgement of a contribution.
 *
 * Every figure and every sentence on this row is a SNAPSHOT of what the
 * document said when it was issued. Nothing is re-rendered on read: the GRA
 * approval it cites can lapse, the trustees can change which causes qualify,
 * and the wording can be revised — and a document already in a donor's hands
 * must keep saying what it said.
 *
 * Append-only, and never withdrawn. A mistake is corrected by issuing a credit
 * note, which is its own document with its own number.
 */
class DonationReceipt extends Model
{
    use HasFactory;
    use HasUlids;

    /** Fields a receipt may gain after issue — delivery, and the rendered PDF. */
    private const MUTABLE = [
        'pdf_media_id', 'sent_at', 'sent_to', 'updated_at',
    ];

    protected $fillable = [
        'donation_id', 'receipt_number', 'financial_year', 'sequence', 'issued_on',
        'donor_name', 'donor_email', 'organisation_name', 'organisation_tin',
        'amount', 'deductible_amount', 'non_deductible_amount', 'currency',
        'amount_in_words', 'cause', 'payment_reference', 'donated_on',
        'cites_approval', 'tax_approval_id', 'approval_reference', 'approval_validity',
        'statement', 'authentication', 'issued_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'currency' => 'GHS',
        'cites_approval' => false,
        'deductible_amount_minor' => 0,
        'non_deductible_amount_minor' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'issued_on' => 'date',
            'donated_on' => 'date',
            'sent_at' => 'datetime',
            'cites_approval' => 'boolean',
            'amount' => MoneyCast::class.':amount_minor,currency',
            'deductible_amount' => MoneyCast::class.':deductible_amount_minor,currency',
            'non_deductible_amount' => MoneyCast::class.':non_deductible_amount_minor,currency',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $receipt): void {
            $illegal = array_diff(array_keys($receipt->getDirty()), self::MUTABLE);

            if ($illegal !== []) {
                throw new RuntimeException(
                    'A receipt is a snapshot of what was issued and cannot be altered: '
                    .implode(', ', $illegal).'. Issue a credit note instead.'
                );
            }
        });

        static::deleting(function (): void {
            throw new RuntimeException(
                'Receipts are never deleted. The series must account for every number in it — '
                .'a gap is what an auditor asks about.'
            );
        });
    }

    /** @return array<int, string> */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return BelongsTo<Donation, $this> */
    public function donation(): BelongsTo
    {
        return $this->belongsTo(Donation::class);
    }

    /** @return BelongsTo<TaxApproval, $this> */
    public function taxApproval(): BelongsTo
    {
        return $this->belongsTo(TaxApproval::class, 'tax_approval_id');
    }

    /** @return BelongsTo<Media, $this> */
    public function pdf(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'pdf_media_id');
    }

    /**
     * Allocate the next number in a financial year's series.
     *
     * A counter row under `lockForUpdate`, not an AUTO_INCREMENT. Auto-increment
     * burns a number on a rolled-back insert, and the resulting gap is exactly
     * the thing an auditor asks the foundation to explain.
     *
     * MUST be called inside the transaction that writes the receipt, so a
     * failure gives the number back rather than stranding it.
     */
    public static function allocateNumber(int $financialYear): array
    {
        if (! DB::transactionLevel()) {
            throw new RuntimeException(
                'Receipt numbers must be allocated inside a database transaction, so a failed '
                .'issue returns the number to the series instead of leaving a gap.'
            );
        }

        $row = DB::table('receipt_sequences')
            ->where('financial_year', $financialYear)
            ->lockForUpdate()
            ->first();

        if ($row === null) {
            DB::table('receipt_sequences')->insert([
                'financial_year' => $financialYear,
                'last_number' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $row = DB::table('receipt_sequences')
                ->where('financial_year', $financialYear)
                ->lockForUpdate()
                ->first();
        }

        $next = (int) $row->last_number + 1;

        DB::table('receipt_sequences')
            ->where('financial_year', $financialYear)
            ->update(['last_number' => $next, 'updated_at' => now()]);

        return [
            'sequence' => $next,
            'receipt_number' => sprintf('SCGHF-R-%d-%06d', $financialYear, $next),
        ];
    }

    /** The three paragraphs, as issued. */
    public function paragraphs(): array
    {
        $decoded = json_decode((string) $this->statement, true);

        return is_array($decoded) ? $decoded : [(string) $this->statement];
    }

    public function amountReceived(): Money
    {
        return $this->amount;
    }

    /** Whether this document is evidence for a section 100 claim. */
    public function supportsTaxClaim(): bool
    {
        return $this->cites_approval && $this->deductible_amount_minor > 0;
    }

    public function markSent(string $to): void
    {
        $this->forceFill(['sent_at' => now(), 'sent_to' => $to])->save();
    }

    public function isSent(): bool
    {
        return $this->sent_at !== null;
    }

    #[Scope]
    protected function inFinancialYear(Builder $query, int $year): void
    {
        $query->where('financial_year', $year)->orderBy('sequence');
    }

    #[Scope]
    protected function unsent(Builder $query): void
    {
        $query->whereNull('sent_at')->whereNotNull('donor_email');
    }
}
