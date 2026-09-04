<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use RuntimeException;

/**
 * News about a sponsored child, on its way to their sponsor.
 *
 * ── Two gates, and neither is a formality ───────────────────────────────────
 *
 * APPROVAL. Nothing reaches a sponsor unread by a member of staff. An update
 * written by a field worker in a hurry can contain a school name, a village, a
 * surname, or a photograph taken in front of a house. The review is where those
 * come out, and it cannot be the same act as writing it.
 *
 * CONSENT, AT THE MOMENT OF SENDING. Re-read from the `consents` table rather
 * than trusted from a flag on the sponsorship, because a consent can expire or
 * be revoked — and the whole point of an expiry is that something checks it.
 * The consent actually relied on is then STORED on the update, so that in two
 * years "we had permission to send that photograph" is a row pointing at a
 * signed form rather than somebody's recollection.
 */
class SponsorshipUpdate extends Model
{
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    protected $fillable = [
        'sponsorship_id', 'title', 'body', 'photograph_id', 'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
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

    /** @return BelongsTo<Sponsorship, $this> */
    public function sponsorship(): BelongsTo
    {
        return $this->belongsTo(Sponsorship::class);
    }

    /** @return BelongsTo<Media, $this> */
    public function photograph(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'photograph_id');
    }

    public function hasPhotograph(): bool
    {
        return $this->photograph_id !== null;
    }

    /**
     * Sign the update off for sending.
     *
     * The approver may not be the author. The same separation that governs
     * payouts, for the same reason: one pair of eyes is not a review, and the
     * pair that wrote it is the pair that will not notice the school badge in
     * the photograph.
     */
    public function approve(User $approver): void
    {
        if ($this->created_by !== null && $approver->getKey() === $this->created_by) {
            throw new RuntimeException(
                'An update about a child cannot be approved by the person who wrote it. The '
                .'review exists to catch a surname, a school name or a house in the background '
                .'— and the person who wrote it is the person who will not see them.'
            );
        }

        if (! $approver->can('consents.manage')) {
            throw new RuntimeException(
                'Approving an update about a child needs the `consents.manage` permission.'
            );
        }

        $this->forceFill([
            'approved_by' => $approver->getKey(),
            'approved_at' => now(),
        ])->save();
    }

    /**
     * Why this update may not go, or null if it may.
     */
    public function sendRejectionReason(): ?string
    {
        if ($this->approved_at === null) {
            return 'This update has not been read and approved by anybody.';
        }

        if ($this->sent_at !== null) {
            return 'This update has already been sent.';
        }

        return $this->sponsorship?->updateRejectionReason($this->hasPhotograph())
            ?? null;
    }

    public function canBeSent(): bool
    {
        return $this->sendRejectionReason() === null;
    }

    /**
     * Record that it went, and under which consent.
     *
     * The consent is resolved HERE, at the moment of sending, and written onto
     * the row. Storing it is what turns a claim into evidence.
     */
    public function markSent(): void
    {
        $reason = $this->sendRejectionReason();

        if ($reason !== null) {
            throw new RuntimeException($reason);
        }

        $consent = $this->sponsorship?->liveConsent(
            $this->hasPhotograph() ? Consent::TYPE_PHOTO : Consent::TYPE_STORY,
        );

        $this->forceFill([
            'consent_id' => $consent?->getKey(),
            'sent_at' => now(),
        ])->save();
    }

    #[Scope]
    protected function awaitingApproval(Builder $query): void
    {
        $query->whereNull('approved_at')->orderBy('created_at');
    }

    #[Scope]
    protected function sendable(Builder $query): void
    {
        $query->whereNotNull('approved_at')->whereNull('sent_at');
    }
}
