<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Per-entity SEO overrides. Every field nullable — see HasSeo for the
 * fallback chain that makes them optional.
 */
class SeoMeta extends Model
{
    protected $table = 'seo_meta';

    protected $fillable = [
        'title', 'description', 'keywords', 'canonical_url',
        'og_title', 'og_description', 'og_image_id', 'og_type',
        'twitter_card', 'no_index', 'no_follow', 'change_frequency', 'priority',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'og_type' => 'website',
        'twitter_card' => 'summary_large_image',
        'no_index' => false,
        'no_follow' => false,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'no_index' => 'boolean',
            'no_follow' => 'boolean',
            'priority' => 'float',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function seoable(): MorphTo
    {
        return $this->morphTo();
    }

    /** The robots directive, e.g. 'noindex, follow'. */
    public function robots(): string
    {
        return implode(', ', [
            $this->no_index ? 'noindex' : 'index',
            $this->no_follow ? 'nofollow' : 'follow',
        ]);
    }
}
