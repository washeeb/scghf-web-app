<?php

declare(strict_types=1);

namespace App\Models;

use App\Communications\CampaignComposer;
use App\Models\Concerns\BelongsToDivision;
use App\Models\Concerns\RecordsAuthor;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * One mailing to a list.
 *
 * ── Two gates before it can go ──────────────────────────────────────────────
 *
 * A campaign cannot be recalled. Two thousand people receive it, or they do
 * not; there is no edit and no undo. So sending is gated on two things that
 * both exist to catch the mistake before it multiplies:
 *
 *   1. A TEST SEND must have happened. A broken merge tag, a dead link or a
 *      subject line with `{{cause_name}}` still in it should be found by one
 *      person looking at their own inbox.
 *
 *   2. An APPROVAL must be recorded, by somebody holding `newsletter.send`.
 *      The permission set already separates drafting from sending; this is
 *      what makes that separation hold however the send is triggered, rather
 *      than only in whichever screen happens to check.
 *
 * Both are defaults in config/communications.php, so a foundation that decides
 * it does not want them can turn them off deliberately rather than discover
 * they were never there.
 *
 * ── Editing after approval revokes it ───────────────────────────────────────
 *
 * Changing the subject or the body after somebody approved it means what they
 * approved is not what would go. The approval is cleared, and has to be given
 * again.
 */
class NewsletterCampaign extends Model
{
    use BelongsToDivision;
    use HasFactory;
    use HasUlids;
    use RecordsAuthor;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_BUILDING = 'building';

    public const STATUS_SENDING = 'sending';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_SENT = 'sent';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'newsletter_id', 'title', 'subject', 'preheader',
        'body_html', 'body_text', 'blocks', 'email_template_id',
        'scheduled_for', 'division_id', 'topics', 'created_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => self::STATUS_DRAFT,
        'recipient_count' => 0,
        'sent_count' => 0,
        'failed_count' => 0,
        'skipped_count' => 0,
    ];

    protected function casts(): array
    {
        return [
            'topics' => 'array',
            'blocks' => 'array',
            'scheduled_for' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'paused_at' => 'datetime',
            'test_sent_at' => 'datetime',
            'approved_at' => 'datetime',
            'recipient_count' => 'integer',
            'sent_count' => 'integer',
            'failed_count' => 'integer',
            'skipped_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Blocks compile to the body on every save, so the sender, the test
        // send and the preview all read one thing. A campaign with no blocks
        // keeps whatever HTML was typed by hand.
        static::saving(function (self $campaign): void {
            if (! empty($campaign->blocks)) {
                $compiled = app(CampaignComposer::class)->compile($campaign->blocks);
                $campaign->body_html = $compiled['html'];
                $campaign->body_text = $compiled['text'];
            }
        });

        static::updating(function (self $campaign): void {
            /*
             * Content changed after approval? The approval no longer describes
             * what would be sent, so it is withdrawn.
             *
             * Deliberately not a refusal to edit: correcting a typo after
             * approval is a normal thing to want to do. What is not normal is
             * that correction going out under somebody else's sign-off.
             */
            $contentChanged = $campaign->isDirty(['subject', 'body_html', 'body_text', 'preheader']);

            if ($contentChanged && $campaign->approved_at !== null) {
                $campaign->approved_at = null;
                $campaign->approved_by = null;
            }

            // The same logic applies to the test send: a test of the old body
            // does not tell anybody anything about the new one.
            if ($contentChanged && $campaign->test_sent_at !== null) {
                $campaign->test_sent_at = null;
            }
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

    /** @return BelongsTo<Newsletter, $this> */
    public function newsletter(): BelongsTo
    {
        return $this->belongsTo(Newsletter::class);
    }

    /** @return HasMany<CampaignRecipient, $this> */
    public function recipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class);
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    // ── Approval ─────────────────────────────────────────────────────────────

    /**
     * Sign a campaign off for sending.
     *
     * The permission is checked here rather than only in the admin panel,
     * because a gate that lives in one screen is a gate that a console command,
     * a queued job or a future API route walks straight past.
     */
    public function approve(User $approver): void
    {
        if (! $approver->can('newsletter.send')) {
            throw new RuntimeException(
                'Approving a campaign for sending needs the `newsletter.send` permission. '
                .'Drafting and sending are separate on purpose.'
            );
        }

        if ($this->status === self::STATUS_SENT) {
            throw new RuntimeException('This campaign has already been sent.');
        }

        $this->forceFill([
            'approved_by' => $approver->getKey(),
            'approved_at' => now(),
        ])->save();
    }

    public function recordTestSend(string $address): void
    {
        $this->forceFill([
            'test_sent_at' => now(),
            'test_sent_to' => $address,
        ])->save();
    }

    // ── The gate ─────────────────────────────────────────────────────────────

    /**
     * Why this campaign may not be sent, or null if it may.
     *
     * A sentence rather than a boolean, so the person looking at it is told
     * what to do next instead of being told no.
     */
    public function sendRejectionReason(): ?string
    {
        if (in_array($this->status, [self::STATUS_SENT, self::STATUS_SENDING], true)) {
            return 'This campaign has already been sent.';
        }

        if ($this->status === self::STATUS_CANCELLED) {
            return 'This campaign was cancelled.';
        }

        if (blank($this->body_html)) {
            return 'The campaign has no body.';
        }

        if (config('communications.newsletter.require_test_send', true) && $this->test_sent_at === null) {
            return 'Send a test to yourself and read it first. A broken link or an unfilled '
                .'variable should be found by one person, not by everybody on the list — a '
                .'campaign cannot be recalled.';
        }

        if (config('communications.newsletter.require_approval', true) && $this->approved_at === null) {
            return 'This campaign has not been approved by anyone holding the `newsletter.send` '
                .'permission.';
        }

        if ($this->recipient_count < 1) {
            return 'The recipient list has not been built, or nobody on this list is eligible.';
        }

        return null;
    }

    public function canBeSent(): bool
    {
        return $this->sendRejectionReason() === null;
    }

    public function assertSendable(): void
    {
        $reason = $this->sendRejectionReason();

        if ($reason !== null) {
            throw new RuntimeException($reason);
        }
    }

    // ── Progress ─────────────────────────────────────────────────────────────

    public function markSending(): void
    {
        $this->forceFill([
            'status' => self::STATUS_SENDING,
            'started_at' => $this->started_at ?? now(),
        ])->save();
    }

    /**
     * Pause a send in flight.
     *
     * Worth having precisely because a send takes hours on this host. Noticing
     * a mistake forty minutes in should stop the other 1,600 messages, and on a
     * platform that dispatches instantly there would be nothing left to stop.
     */
    public function pause(string $reason): void
    {
        $this->forceFill([
            'status' => self::STATUS_PAUSED,
            'paused_at' => now(),
            'pause_reason' => $reason,
        ])->save();
    }

    public function resume(): void
    {
        if ($this->status !== self::STATUS_PAUSED) {
            return;
        }

        $this->forceFill([
            'status' => self::STATUS_SENDING,
            'paused_at' => null,
            'pause_reason' => null,
        ])->save();
    }

    public function cancel(string $reason): void
    {
        if ($this->status === self::STATUS_SENT) {
            throw new RuntimeException('A campaign that has already been sent cannot be cancelled.');
        }

        $this->forceFill([
            'status' => self::STATUS_CANCELLED,
            'pause_reason' => $reason,
        ])->save();

        // Nothing still queued should go out after a cancellation.
        $this->recipients()->where('status', CampaignRecipient::STATUS_PENDING)->update([
            'status' => CampaignRecipient::STATUS_SKIPPED,
            'skip_reason' => 'campaign_cancelled',
            'updated_at' => now(),
        ]);
    }

    /** Recount from the recipient rows, which are the truth. */
    public function refreshCounters(): void
    {
        $counts = $this->recipients()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $this->forceFill([
            'recipient_count' => (int) $counts->sum(),
            'sent_count' => (int) $counts->get(CampaignRecipient::STATUS_SENT, 0),
            'failed_count' => (int) $counts->get(CampaignRecipient::STATUS_FAILED, 0),
            'skipped_count' => (int) $counts->get(CampaignRecipient::STATUS_SKIPPED, 0),
        ])->save();
    }

    public function isComplete(): bool
    {
        return $this->recipients()->where('status', CampaignRecipient::STATUS_PENDING)->doesntExist();
    }

    public function markComplete(): void
    {
        $this->refreshCounters();

        $this->forceFill([
            'status' => self::STATUS_SENT,
            'completed_at' => now(),
        ])->save();
    }

    /**
     * How far through, as a percentage.
     *
     * Shown because a send takes hours here. "412 of 2,000" is the answer to
     * the question staff actually ask, and without it a slow send looks like a
     * broken one.
     */
    public function progressPercent(): int
    {
        if ($this->recipient_count < 1) {
            return 0;
        }

        $done = $this->sent_count + $this->failed_count + $this->skipped_count;

        return (int) floor($done / $this->recipient_count * 100);
    }

    /** A rough finish time, from the host's hourly allowance. */
    public function estimatedHoursRemaining(): float
    {
        $perHour = max(1, (int) config('communications.throttle.mail.per_hour', 200));
        $remaining = $this->recipients()->where('status', CampaignRecipient::STATUS_PENDING)->count();

        return round($remaining / $perHour, 1);
    }

    /**
     * Queue the pending recipients that are still eligible.
     *
     * Called repeatedly as the outbox drains, so it takes whatever the current
     * allowance is and leaves the rest. `DB::transaction` around the claim
     * keeps two overlapping runs from queueing the same recipient twice.
     *
     * @return int how many were queued
     */
    public function queueNext(int $limit): int
    {
        return DB::transaction(function () use ($limit): int {
            $batch = $this->recipients()
                ->where('status', CampaignRecipient::STATUS_PENDING)
                ->whereNull('claimed_at')
                ->limit($limit)
                ->lockForUpdate()
                ->get();

            foreach ($batch as $recipient) {
                $recipient->forceFill(['claimed_at' => now()])->save();
            }

            return $batch->count();
        });
    }

    #[Scope]
    protected function sendable(Builder $query): void
    {
        $query->whereIn('status', [self::STATUS_SCHEDULED, self::STATUS_SENDING])
            ->whereNotNull('approved_at');
    }
}
