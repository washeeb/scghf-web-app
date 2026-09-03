<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * An in-app notification, and the record of how it was dispatched.
 *
 * Separate from Laravel's own `notifications` table because this records the
 * DISPATCH as well as the message: which channels were asked for, and which
 * actually delivered.
 *
 * The gap between the two is the useful part. "We tried to text them, the SMS
 * was suppressed, so the email is the only copy that exists" is a question
 * somebody will need answered — usually while a donor is on the phone.
 */
class NotificationLog extends Model
{
    use HasFactory;
    use HasUlids;

    public const LEVEL_INFO = 'info';

    public const LEVEL_SUCCESS = 'success';

    public const LEVEL_WARNING = 'warning';

    public const LEVEL_URGENT = 'urgent';

    protected $fillable = [
        'notifiable_type', 'notifiable_id', 'key', 'title', 'body',
        'action_url', 'action_label', 'level',
        'channels', 'delivered_channels',
        'related_type', 'related_id',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'level' => self::LEVEL_INFO,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'channels' => 'array',
            'delivered_channels' => 'array',
            'read_at' => 'datetime',
            'dismissed_at' => 'datetime',
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

    /** @return MorphTo<Model, $this> */
    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return MorphTo<Model, $this> */
    public function related(): MorphTo
    {
        return $this->morphTo();
    }

    public function markRead(): void
    {
        if ($this->read_at === null) {
            $this->forceFill(['read_at' => now()])->save();
        }
    }

    public function dismiss(): void
    {
        $this->forceFill([
            'dismissed_at' => now(),
            'read_at' => $this->read_at ?? now(),
        ])->save();
    }

    /** Record that a channel actually delivered. */
    public function recordDelivery(string $channel): void
    {
        $delivered = $this->delivered_channels ?? [];

        if (! in_array($channel, $delivered, true)) {
            $delivered[] = $channel;
            $this->forceFill(['delivered_channels' => $delivered])->save();
        }
    }

    /**
     * Channels that were asked for and did not deliver.
     *
     * @return array<int, string>
     */
    public function undelivered(): array
    {
        return array_values(array_diff(
            $this->channels ?? [],
            $this->delivered_channels ?? [],
        ));
    }

    /** Nothing at all reached them. */
    public function reachedNobody(): bool
    {
        return ($this->delivered_channels ?? []) === [];
    }

    public function isUnread(): bool
    {
        return $this->read_at === null && $this->dismissed_at === null;
    }

    #[Scope]
    protected function unread(Builder $query): void
    {
        $query->whereNull('read_at')->whereNull('dismissed_at');
    }

    #[Scope]
    protected function for(Builder $query, Model $notifiable): void
    {
        $query->where('notifiable_type', $notifiable->getMorphClass())
            ->where('notifiable_id', $notifiable->getKey());
    }
}
