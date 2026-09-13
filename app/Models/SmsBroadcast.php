<?php

declare(strict_types=1);

namespace App\Models;

use App\Communications\PhoneNumber;
use App\Communications\SmsSegmenter;
use App\Models\Concerns\RecordsAuthor;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * A text to many people.
 *
 * ── Who may be written to ───────────────────────────────────────────────────
 *
 * `donors_sms`: donors who ticked "send me updates by SMS", with a phone
 * number, minus anybody suppressed. That is the only list the foundation
 * holds consent for. `custom`: numbers pasted in — for the volunteers on a
 * shift, the parents at a school — which the person pasting them is
 * responsible for, and which the suppression list still checks.
 *
 * ── The cost is shown before anybody presses send ───────────────────────────
 *
 * Segments × recipients × the contracted rate, in pesewas, on the screen
 * beside the button. A broadcast is refused past the segment budget for the
 * same reason a template is.
 *
 * ── Two people, like a newsletter ───────────────────────────────────────────
 *
 * Whoever drafts it may not approve it. The approval is what queues the
 * messages, into the ordinary outbox, which throttles and respects quiet
 * hours and checks the suppression list again for every single number at
 * the moment it goes.
 */
class SmsBroadcast extends Model
{
    use HasFactory;
    use HasUlids;
    use RecordsAuthor;
    use SoftDeletes;

    public const TEMPLATE_KEY = 'sms.broadcast';

    public const AUDIENCE_DONORS = 'donors_sms';

    public const AUDIENCE_CUSTOM = 'custom';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = ['title', 'body', 'audience', 'custom_numbers', 'scheduled_for', 'created_by'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'audience' => self::AUDIENCE_DONORS,
        'status' => self::STATUS_DRAFT,
        'currency' => 'GHS',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'scheduled_for' => 'datetime',
            'approved_at' => 'datetime',
            'queued_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $broadcast): void {
            $segmenter = app(SmsSegmenter::class);
            $max = (int) config('communications.sms.max_segments', 2);
            $segments = $segmenter->segments((string) $broadcast->body);

            if ($segments > $max) {
                throw new RuntimeException(sprintf(
                    'This text runs to %d segments and the budget is %d. Every segment is billed for every recipient; shorten it.',
                    $segments,
                    $max,
                ));
            }

            $broadcast->segments = $segments;
            $broadcast->recipient_count = $broadcast->recipients()->count();
            $broadcast->estimated_cost_minor = $segmenter->estimatedCostMinor((string) $broadcast->body, $broadcast->recipient_count);

            // Any edit after approval is a different message.
            if ($broadcast->exists && $broadcast->approved_at !== null && $broadcast->isDirty(['body', 'audience', 'custom_numbers'])) {
                $broadcast->approved_at = null;
                $broadcast->approved_by = null;
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

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return HasMany<SmsLog, $this> */
    public function logs(): HasMany
    {
        return $this->hasMany(SmsLog::class, 'related_id')->where('related_type', $this->getMorphClass());
    }

    /**
     * The numbers this would go to, normalised, deduplicated, suppression
     * applied — as they stand now, not as they stood when it was drafted.
     *
     * @return Collection<int, array{number: string, name: ?string}>
     */
    public function recipients(): Collection
    {
        $rows = match ($this->audience) {
            // One per line or comma; the spaces people type inside a number are not separators.
            self::AUDIENCE_CUSTOM => collect(preg_split('/[\r\n,;]+/', (string) $this->custom_numbers) ?: [])
                ->map(fn (string $raw): string => trim($raw))
                ->filter()
                ->map(fn (string $raw): array => ['number' => PhoneNumber::tryNormalise($raw), 'name' => null]),
            default => Donor::query()
                ->where('consent_sms', true)
                ->whereNotNull('phone')
                ->get(['phone', 'name'])
                ->map(fn (Donor $d): array => ['number' => PhoneNumber::tryNormalise($d->phone), 'name' => $d->name]),
        };

        $unique = $rows->filter(fn (array $r): bool => $r['number'] !== null)->unique('number')->values();

        $suppressed = Suppression::query()
            ->where('channel', Suppression::CHANNEL_SMS)
            ->whereIn('address', $unique->pluck('number')->all())
            ->pluck('address')
            ->all();

        return $unique->reject(fn (array $r): bool => in_array($r['number'], $suppressed, true))->values();
    }

    public function approve(User $approver): void
    {
        if ($this->created_by !== null && $this->created_by === $approver->getKey()) {
            throw new RuntimeException('A broadcast cannot be approved by the person who wrote it. Ask a second person holding `newsletter.send`.');
        }

        if (! $approver->can('newsletter.send')) {
            throw new RuntimeException('Approving a broadcast needs the `newsletter.send` permission.');
        }

        if ($this->status !== self::STATUS_DRAFT) {
            throw new RuntimeException('Only a draft can be approved.');
        }

        $this->forceFill(['approved_by' => $approver->getKey(), 'approved_at' => now()])->save();
    }

    public function isApproved(): bool
    {
        return $this->approved_at !== null;
    }
}
