<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SettingType;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
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
        'updated_by',
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

    protected static function booted(): void
    {
        static::saving(function (self $setting): void {
            /*
             * Who last changed this.
             *
             * `updated_by` has been on the table since the settings migration
             * and was written by nothing, so the column existed and the
             * relationship resolved and the answer was always "nobody" — the
             * worst shape for an audit field, because it reads as a fact.
             *
             * Only stamped when the VALUE changes, and only for a real person:
             * a seeder or a scheduled command has no user, and attributing its
             * work to whoever happened to be logged in would be a lie.
             */
            if ($setting->isDirty('value') && auth()->hasUser()) {
                $setting->updated_by = auth()->id();
            }
        });

        /*
         * The cache is a single array of the whole table, so any write to any
         * row invalidates it. On the model rather than only in
         * `Settings::set()`, because the admin screen saves models directly —
         * and a settings change that does not appear on the site until a cache
         * expires is indistinguishable, to the person who made it, from one
         * that did not save.
         */
        static::saved(fn () => app(Settings::class)->flush());
        static::deleted(fn () => app(Settings::class)->flush());

        static::updating(function (self $setting): void {
            /*
             * Record what it used to be, before it stops being that.
             *
             * On the model rather than in Filament, so it holds however the
             * value is changed — an admin screen, a seeder, a console command
             * or a forceFill. A history that only Filament wrote to would have
             * a hole in it exactly where somebody bypassed Filament.
             *
             * Only when the VALUE changes: relabelling a setting or reordering
             * it is not a change to what the site says, and recording those
             * would bury the changes that matter.
             */
            if (! $setting->isDirty('value')) {
                return;
            }

            SettingHistoryEntry::recordChange(
                $setting,
                $setting->getOriginal('value'),
                $setting->value,
            );
        });
    }

    /** `contact.phone_primary` — how every caller refers to a setting. */
    public function qualifiedKey(): string
    {
        return $this->group.'.'.$this->key;
    }

    /**
     * Everything that ever happened to this setting.
     *
     * @return Collection<int, SettingHistoryEntry>
     */
    public function history(): Collection
    {
        return SettingHistoryEntry::forKey($this->qualifiedKey());
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
