<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * What a setting used to be.
 *
 * ── Why settings need a history and most tables do not ──────────────────────
 *
 * The settings table is not only colours and copy. It holds the GRA approval
 * reference and its validity dates, the receipt signatory, the organisation's
 * legal name and TIN, and the donation presets — values that appear on
 * documents going to a regulator and on the acknowledgements donors keep.
 *
 * "It used to say something else, and the receipts we issued in March prove it"
 * is a question somebody will eventually ask. Nothing else in this application
 * could answer it: the setting row holds only the current value, and Filament
 * would happily overwrite the previous one without comment.
 *
 * ── Secrets are recorded as CHANGED, never as values ────────────────────────
 *
 * An encrypted setting logs that it changed, when and by whom, with both values
 * redacted. A history table is the last place a plaintext copy of a secret
 * should accumulate — it would outlive every rotation, and be the one copy
 * nobody remembers to clear.
 *
 * ── Append-only ─────────────────────────────────────────────────────────────
 *
 * The point of a history is that it is not editable. `created_at` only; the
 * model refuses updates and deletes.
 */
class SettingHistoryEntry extends Model
{
    use HasFactory;

    protected $table = 'settings_history';

    public const UPDATED_AT = null;

    protected $fillable = [
        'setting_key', 'old_value', 'new_value', 'is_redacted',
        'changed_by', 'changed_by_label', 'ip_address', 'changed_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_redacted' => false,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_redacted' => 'boolean',
            'changed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new RuntimeException(
                'Settings history is append-only. A history somebody can edit is not a history.'
            );
        });

        static::deleting(function (): void {
            throw new RuntimeException(
                'Settings history cannot be deleted. It is the only record of what a receipt '
                .'or a GRA approval reference used to say.'
            );
        });
    }

    /** @return BelongsTo<User, $this> */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    /**
     * Record a change to a setting.
     *
     * Called from the Setting model's own `updating` hook, so it holds however
     * the value is changed — Filament, a seeder, a console command or a
     * `forceFill`. A history that only Filament wrote to would be a history
     * with a hole in it exactly where somebody bypassed Filament.
     */
    public static function recordChange(Setting $setting, mixed $oldRaw, mixed $newRaw): self
    {
        $redact = (bool) $setting->is_encrypted;

        return static::create([
            'setting_key' => $setting->qualifiedKey(),
            'old_value' => $redact ? null : self::stringify($oldRaw),
            'new_value' => $redact ? null : self::stringify($newRaw),
            'is_redacted' => $redact,
            'changed_by' => auth()->id(),
            'changed_by_label' => self::actorLabel(),
            'ip_address' => app()->runningInConsole() ? null : request()->ip(),
            // `changed_at` is when the change happened; `created_at` is set by
            // Eloquent and is when the row was written. They are the same
            // moment here, but they are not the same fact, and a backfill would
            // make the difference visible.
            'changed_at' => now(),
        ]);
    }

    /**
     * Everything that ever happened to one setting, newest first.
     *
     * @return Collection<int, self>
     */
    public static function forKey(string $qualifiedKey): Collection
    {
        return static::query()
            ->where('setting_key', $qualifiedKey)
            ->orderByDesc('changed_at')
            ->get();
    }

    /**
     * What a setting held at a given moment.
     *
     * The method that answers "what did the receipts issued in March say?".
     * Returns null when the setting had not been changed by then, which is not
     * the same as it having been empty — so the caller is told which case it is
     * rather than being handed a misleading blank.
     */
    public static function valueAt(string $qualifiedKey, \DateTimeInterface $moment): ?string
    {
        $change = static::query()
            ->where('setting_key', $qualifiedKey)
            ->where('changed_at', '>', $moment)
            ->orderBy('changed_at')
            ->first();

        // The first change AFTER the moment carries, as its old value, what the
        // setting held at the moment.
        if ($change !== null) {
            return $change->is_redacted ? '[redacted]' : $change->old_value;
        }

        // Nothing changed after that moment, so whatever it holds now is what
        // it held then.
        return Setting::query()
            ->where('group', Str::before($qualifiedKey, '.'))
            ->where('key', Str::after($qualifiedKey, '.'))
            ->first()?->value;
    }

    public function summary(): string
    {
        if ($this->is_redacted) {
            return sprintf(
                '%s changed (value not recorded) by %s on %s.',
                $this->setting_key,
                $this->changed_by_label ?? 'the system',
                $this->changed_at?->format('j M Y H:i') ?? '',
            );
        }

        return sprintf(
            '%s: "%s" → "%s" by %s on %s.',
            $this->setting_key,
            Str::limit((string) $this->old_value, 60),
            Str::limit((string) $this->new_value, 60),
            $this->changed_by_label ?? 'the system',
            $this->changed_at?->format('j M Y H:i') ?? '',
        );
    }

    private static function stringify(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_scalar($value) => (string) $value,
            default => json_encode($value) ?: null,
        };
    }

    private static function actorLabel(): ?string
    {
        $user = auth()->user();

        if ($user instanceof User) {
            return trim(($user->name ?? '').' <'.($user->email ?? '').'>');
        }

        return app()->runningInConsole() ? 'console' : null;
    }

    #[Scope]
    protected function recent(Builder $query): void
    {
        $query->orderByDesc('changed_at');
    }
}
