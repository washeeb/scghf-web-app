<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One entry in a case's log. Written once, by somebody, at a time.
 *
 * Append-only is enforced here, not only promised: `updating` and
 * `deleting` throw. The retention runner removes notes by removing the
 * case (the foreign key cascades), which is a database-level delete and
 * does not pass through this model — deliberately, because that is the
 * one destruction that is lawful.
 *
 * @property int $id
 * @property int $beneficiary_id
 * @property int|null $author_id
 * @property string $kind
 * @property string $body
 * @property Carbon|null $created_at
 */
class BeneficiaryNote extends Model
{
    public const UPDATED_AT = null;

    public const KIND_NOTE = 'note';

    public const KIND_STATUS = 'status';

    public const KIND_CONSENT = 'consent';

    public const KIND_DOCUMENT = 'document';

    public const KIND_REVEAL = 'reveal';

    protected $fillable = ['beneficiary_id', 'author_id', 'kind', 'body'];

    /** @var array<string, mixed> */
    protected $attributes = ['kind' => self::KIND_NOTE];

    protected function casts(): array
    {
        return [
            'body' => 'encrypted',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('A case note is never edited. Add another note.');
        });

        static::deleting(function (): never {
            throw new LogicException('A case note is never deleted on its own; it goes with the case at retention expiry.');
        });
    }

    /** @return BelongsTo<Beneficiary, $this> */
    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Beneficiary::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
