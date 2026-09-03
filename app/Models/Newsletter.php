<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToDivision;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A list somebody can be on.
 *
 * `subscribers.topics` already lets somebody take the impact update without the
 * fundraising appeals. This is the table those topics point at, which is what
 * turns that preference from a string in a JSON column into something the
 * sender actually honours.
 */
class Newsletter extends Model
{
    use BelongsToDivision;
    use HasFactory;
    use HasUlids;

    protected $fillable = [
        'key', 'name', 'description', 'topic', 'division_id',
        'from_name', 'from_address', 'reply_to', 'email_template_id',
        'cadence', 'is_active', 'sort_order',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_active' => true,
        'sort_order' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
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

    /** @return HasMany<NewsletterCampaign, $this> */
    public function campaigns(): HasMany
    {
        return $this->hasMany(NewsletterCampaign::class);
    }

    /** @return BelongsTo<EmailTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class, 'email_template_id');
    }

    /**
     * Confirmed subscribers who asked for this topic.
     *
     * Confirmed only. The double opt-in on `subscribers` is worth nothing if a
     * campaign can be built from pending rows — and a pending row is somebody
     * whose address may have been typed in by a third party.
     *
     * A subscriber with no stated topics is included: they signed up before the
     * list existed, or through a footer form that never asked. Excluding them
     * would silently drop the majority of an existing list the first time
     * topics were introduced.
     *
     * @return Builder<Subscriber>
     */
    public function subscriberQuery(): Builder
    {
        return Subscriber::query()
            ->where('status', 'confirmed')
            ->whereNull('unsubscribed_at')
            ->where(function (Builder $q): void {
                $q->whereNull('topics')
                    ->orWhereJsonContains('topics', $this->topic);
            });
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
