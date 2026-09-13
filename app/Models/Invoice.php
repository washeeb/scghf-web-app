<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use RuntimeException;

/**
 * A sales receipt for a shop purchase.
 *
 * **A different document, on a different series, from a donation
 * acknowledgement.** `SCGHF-INV-2026-000012`, never `SCGHF-R-…`. The compliance
 * rule is that shop sales and charitable donations have separate accounting,
 * receipts, payment types and reporting — and two series that could interleave
 * would make that impossible to demonstrate to an auditor even if the intent
 * were right.
 *
 * It carries an explicit statement saying what it is not, because the customer
 * holding it is the person most likely to assume otherwise at tax time.
 *
 * Snapshotted and append-only, for the same reasons as an acknowledgement.
 */
class Invoice extends Model
{
    use HasFactory;
    use HasUlids;

    /** What may be added after issue: the rendered PDF, and delivery. */
    private const MUTABLE = ['pdf_media_id', 'sent_at', 'sent_to', 'updated_at'];

    protected $fillable = [
        'order_id', 'invoice_number', 'financial_year', 'sequence', 'issued_on',
        'customer_name', 'customer_email', 'organisation_name', 'organisation_tin',
        'subtotal', 'shipping', 'discount', 'total', 'currency',
        'total_in_words', 'statement',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'currency' => 'GHS',
        'shipping_minor' => 0,
        'discount_minor' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'issued_on' => 'date',
            'sent_at' => 'datetime',
            'subtotal' => MoneyCast::class.':subtotal_minor,currency',
            'shipping' => MoneyCast::class.':shipping_minor,currency',
            'discount' => MoneyCast::class.':discount_minor,currency',
            'total' => MoneyCast::class.':total_minor,currency',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $invoice): void {
            $illegal = array_diff(array_keys($invoice->getDirty()), self::MUTABLE);

            if ($illegal !== []) {
                throw new RuntimeException(
                    'An invoice is a snapshot of what was issued and cannot be altered: '
                    .implode(', ', $illegal).'. Raise a credit note instead.'
                );
            }
        });

        static::deleting(function (): void {
            throw new RuntimeException(
                'Invoices are never deleted. The series must account for every number in it.'
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

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** The signed link to the PDF, for the confirmation email. */
    public function downloadUrl(int $days = 90): string
    {
        return URL::temporarySignedRoute('invoices.download', now()->addDays($days), ['invoice' => $this->ulid]);
    }

    /** @return BelongsTo<Media, $this> */
    public function pdf(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'pdf_media_id');
    }

    /**
     * Allocate the next invoice number.
     *
     * Its own counter table, entirely separate from the donation
     * acknowledgement series. Two series sharing a counter would interleave,
     * and the separation the compliance rule requires would exist in intent
     * only.
     *
     * @return array{sequence: int, invoice_number: string}
     */
    public static function allocateNumber(int $financialYear): array
    {
        if (! DB::transactionLevel()) {
            throw new RuntimeException(
                'Invoice numbers must be allocated inside a database transaction, so a failed '
                .'issue returns the number to the series instead of leaving a gap.'
            );
        }

        $row = DB::table('invoice_sequences')
            ->where('financial_year', $financialYear)
            ->lockForUpdate()
            ->first();

        if ($row === null) {
            DB::table('invoice_sequences')->insert([
                'financial_year' => $financialYear,
                'last_number' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $row = DB::table('invoice_sequences')
                ->where('financial_year', $financialYear)
                ->lockForUpdate()
                ->first();
        }

        $next = (int) $row->last_number + 1;

        DB::table('invoice_sequences')
            ->where('financial_year', $financialYear)
            ->update(['last_number' => $next, 'updated_at' => now()]);

        return [
            'sequence' => $next,
            'invoice_number' => sprintf('SCGHF-INV-%d-%06d', $financialYear, $next),
        ];
    }

    public function markSent(string $to): void
    {
        $this->forceFill(['sent_at' => now(), 'sent_to' => $to])->save();
    }

    public function isSent(): bool
    {
        return $this->sent_at !== null;
    }

    /**
     * Whether this document could be mistaken for a donation acknowledgement.
     *
     * Asserted by a test. The two series must never collide, and the check is
     * cheap enough to keep.
     */
    public function usesSalesSeries(): bool
    {
        return str_starts_with((string) $this->invoice_number, 'SCGHF-INV-');
    }

    #[Scope]
    protected function inFinancialYear(Builder $query, int $year): void
    {
        $query->where('financial_year', $year)->orderBy('sequence');
    }
}
