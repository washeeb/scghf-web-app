<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SettingType;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

/**
 * @property string $group
 * @property string $key
 * @property string|null $value
 * @property SettingType $type
 * @property bool $is_public
 */
class Setting extends Model
{
    protected $fillable = [
        'group', 'key', 'value', 'type', 'label', 'description',
        'is_public', 'is_encrypted', 'is_locked', 'validation', 'options', 'sort_order',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => SettingType::class,
            'is_public' => 'boolean',
            'is_encrypted' => 'boolean',
            'is_locked' => 'boolean',
            'options' => 'array',
        ];
    }

    /** `contact.phone_primary` — how every caller refers to a setting. */
    public function qualifiedKey(): string
    {
        return $this->group.'.'.$this->key;
    }

    /**
     * The stored string, decrypted if needed, cast to its declared type.
     *
     * Decryption failure returns null rather than throwing: a rotated APP_KEY
     * would otherwise take the whole site down at boot, when the honest outcome
     * is "this one setting is unreadable and the preflight check should say so".
     */
    public function typedValue(): mixed
    {
        $raw = $this->value;

        if ($raw !== null && $this->is_encrypted) {
            try {
                $raw = Crypt::decryptString($raw);
            } catch (\Throwable) {
                return null;
            }
        }

        return $this->type->cast($raw);
    }

    public function setTypedValue(mixed $value): void
    {
        $serialised = $this->type->serialise($value);

        $this->value = ($serialised !== null && $this->is_encrypted)
            ? Crypt::encryptString($serialised)
            : $serialised;
    }

    /** True when this setting still holds a {{PLACEHOLDER}} or nothing at all. */
    public function isUnfilled(): bool
    {
        return $this->value === null
            || trim($this->value) === ''
            || (bool) preg_match('/\{\{[A-Z_]+\}\}/', $this->value);
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    #[Scope]
    protected function public(Builder $query): void
    {
        $query->where('is_public', true);
    }

    #[Scope]
    protected function inGroup(Builder $query, string $group): void
    {
        $query->where('group', $group)->orderBy('sort_order');
    }
}
