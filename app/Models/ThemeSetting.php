<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\ContrastChecker;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $theme
 * @property string $token
 * @property string $category
 * @property string $value
 */
class ThemeSetting extends Model
{
    protected $fillable = [
        'theme', 'token', 'category', 'value', 'label', 'description',
        'contrast_against', 'min_contrast', 'is_locked', 'sort_order',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_locked' => 'boolean',
            'min_contrast' => 'float',
        ];
    }

    public function isColour(): bool
    {
        return $this->category === 'colour';
    }

    /** The token this one is checked against, in the same theme. */
    public function contrastPartner(): ?self
    {
        if ($this->contrast_against === null) {
            return null;
        }

        return static::query()
            ->where('theme', $this->theme)
            ->where('token', $this->contrast_against)
            ->first();
    }

    /** Null when this token has no contrast obligation or its partner is missing. */
    public function contrastRatio(): ?float
    {
        $partner = $this->contrastPartner();

        if ($partner === null || ! $this->isColour()) {
            return null;
        }

        return app(ContrastChecker::class)->ratioRounded($this->value, $partner->value);
    }

    /**
     * Whether this token still meets the contrast it declares it needs.
     *
     * True when there is no obligation — a token that never had to be legible
     * against anything cannot fail.
     */
    public function meetsContrast(): bool
    {
        $ratio = $this->contrastRatio();

        if ($ratio === null || $this->min_contrast === null) {
            return true;
        }

        return $ratio >= $this->min_contrast;
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    #[Scope]
    protected function forTheme(Builder $query, string $theme): void
    {
        $query->where('theme', $theme)->orderBy('category')->orderBy('sort_order');
    }

    #[Scope]
    protected function colours(Builder $query): void
    {
        $query->where('category', 'colour');
    }

    /** Tokens carrying a contrast obligation — what the AA audit iterates. */
    #[Scope]
    protected function withContrastObligation(Builder $query): void
    {
        $query->where('category', 'colour')
            ->whereNotNull('contrast_against')
            ->whereNotNull('min_contrast');
    }
}
