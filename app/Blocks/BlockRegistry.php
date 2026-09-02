<?php

declare(strict_types=1);

namespace App\Blocks;

use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * The curated block library.
 *
 * Blueprint §3.2 rejected the "unlimited freeform blocks" page-builder model
 * deliberately: it is how a CMS becomes unmaintainable and off-brand. These are
 * the blocks the design supports, each with a Blade view that respects the
 * theme tokens, and nothing else can be placed.
 *
 * The block list mirrors the section inventory reused from the KidHope
 * reference (§3.1), reshaped around the foundation's four divisions.
 */
class BlockRegistry
{
    /** @var Collection<string, BlockDefinition>|null */
    private ?Collection $blocks = null;

    /** @return Collection<string, BlockDefinition> */
    public function all(): Collection
    {
        return $this->blocks ??= collect($this->define())
            ->keyBy(fn (BlockDefinition $b): string => $b->key);
    }

    public function get(string $key): BlockDefinition
    {
        $block = $this->all()->get($key);

        if ($block === null) {
            throw new InvalidArgumentException("Unknown block type: {$key}");
        }

        return $block;
    }

    public function has(string $key): bool
    {
        return $this->all()->has($key);
    }

    /** @return array<int, string> */
    public function keys(): array
    {
        return $this->all()->keys()->all();
    }

    /** @return Collection<string, Collection<string, BlockDefinition>> */
    public function byCategory(): Collection
    {
        return $this->all()->groupBy(fn (BlockDefinition $b): string => $b->category);
    }

    /** @return array<int, BlockDefinition> */
    private function define(): array
    {
        return [
            // ── Hero and headers ─────────────────────────────────────────────
            new BlockDefinition(
                key: 'hero',
                name: 'Hero',
                description: 'Full-width image with a headline and up to two calls to action.',
                category: 'Headers',
                icon: 'heroicon-o-photo',
                fields: [
                    'eyebrow' => ['type' => 'string', 'max' => 80],
                    'heading' => ['type' => 'string', 'required' => true, 'max' => 160],
                    'subheading' => ['type' => 'text', 'max' => 400],
                    'image' => ['type' => 'media'],
                    // Art-directed mobile crop. The desktop hero downscaled is
                    // the single biggest LCP cost on a 3G connection.
                    'image_mobile' => ['type' => 'media'],
                    'primary_cta_label' => ['type' => 'string', 'max' => 40],
                    'primary_cta_url' => ['type' => 'string', 'max' => 500],
                    'secondary_cta_label' => ['type' => 'string', 'max' => 40],
                    'secondary_cta_url' => ['type' => 'string', 'max' => 500],
                    'overlay_opacity' => ['type' => 'integer', 'default' => 55],
                ],
                maxPerPage: 1,
            ),

            new BlockDefinition(
                key: 'page-header',
                name: 'Page header',
                description: 'Compact title band for interior pages.',
                category: 'Headers',
                icon: 'heroicon-o-bars-3-bottom-left',
                fields: [
                    'heading' => ['type' => 'string', 'required' => true, 'max' => 160],
                    'subheading' => ['type' => 'text', 'max' => 300],
                    'image' => ['type' => 'media'],
                ],
                maxPerPage: 1,
            ),

            // ── Content ──────────────────────────────────────────────────────
            new BlockDefinition(
                key: 'rich-text',
                name: 'Rich text',
                description: 'A block of formatted prose.',
                category: 'Content',
                icon: 'heroicon-o-document-text',
                fields: [
                    'heading' => ['type' => 'string', 'max' => 160],
                    'body' => ['type' => 'html', 'required' => true],
                    'width' => ['type' => 'string', 'default' => 'prose'],
                ],
            ),

            new BlockDefinition(
                key: 'split-content',
                name: 'Text and image',
                description: 'Prose beside an image, with the image on either side.',
                category: 'Content',
                icon: 'heroicon-o-view-columns',
                fields: [
                    'eyebrow' => ['type' => 'string', 'max' => 80],
                    'heading' => ['type' => 'string', 'required' => true, 'max' => 160],
                    'body' => ['type' => 'html'],
                    'image' => ['type' => 'media'],
                    'image_position' => ['type' => 'string', 'default' => 'left'],
                    'cta_label' => ['type' => 'string', 'max' => 40],
                    'cta_url' => ['type' => 'string', 'max' => 500],
                ],
            ),

            new BlockDefinition(
                key: 'feature-grid',
                name: 'Feature cards',
                description: 'Icon cards in a row. Used for the four divisions on the homepage.',
                category: 'Content',
                icon: 'heroicon-o-squares-2x2',
                fields: [
                    'heading' => ['type' => 'string', 'max' => 160],
                    'intro' => ['type' => 'text'],
                    'columns' => ['type' => 'integer', 'default' => 4],
                    'items' => ['type' => 'repeater', 'default' => []],
                ],
            ),

            new BlockDefinition(
                key: 'divisions',
                name: 'The four divisions',
                description: 'Life Spring, BrightPath, Legacy of Love and Every Soul, each in its own accent colour.',
                category: 'Content',
                icon: 'heroicon-o-rectangle-group',
                fields: [
                    'heading' => ['type' => 'string', 'max' => 160],
                    'intro' => ['type' => 'text'],
                    'show_focus_areas' => ['type' => 'boolean', 'default' => true],
                ],
                maxPerPage: 1,
            ),

            new BlockDefinition(
                key: 'core-values',
                name: 'Core values',
                description: 'The seven values from the foundation profile.',
                category: 'Content',
                icon: 'heroicon-o-heart',
                fields: [
                    'heading' => ['type' => 'string', 'max' => 160],
                    'intro' => ['type' => 'text'],
                ],
            ),

            // ── Fundraising ──────────────────────────────────────────────────
            new BlockDefinition(
                key: 'donation-widget',
                name: 'Donation widget',
                description: 'Preset amounts, designation and the option to cover the transaction fee.',
                category: 'Fundraising',
                icon: 'heroicon-o-gift',
                fields: [
                    'heading' => ['type' => 'string', 'max' => 160],
                    'intro' => ['type' => 'text'],
                    // Null falls back to donations.presets in settings, so the
                    // amounts are managed in one place unless a campaign
                    // deliberately overrides them.
                    'preset_amounts' => ['type' => 'list', 'default' => []],
                    'default_cause_id' => ['type' => 'integer'],
                    'show_frequency_toggle' => ['type' => 'boolean', 'default' => true],
                ],
                // Two donation forms on one page is a conversion problem, not a
                // feature — a donor should never have to choose which to use.
                maxPerPage: 1,
            ),

            new BlockDefinition(
                key: 'featured-causes',
                name: 'Featured causes',
                description: 'Cause cards with live progress towards goal.',
                category: 'Fundraising',
                icon: 'heroicon-o-banknotes',
                fields: [
                    'heading' => ['type' => 'string', 'max' => 160],
                    'intro' => ['type' => 'text'],
                    'limit' => ['type' => 'integer', 'default' => 3],
                    'division_id' => ['type' => 'integer'],
                    'cta_label' => ['type' => 'string', 'max' => 40],
                ],
            ),

            new BlockDefinition(
                key: 'impact-stats',
                name: 'Impact numbers',
                description: 'Large figures on a brand-green band, drawn from recorded impact metrics.',
                category: 'Fundraising',
                icon: 'heroicon-o-chart-bar',
                fields: [
                    'heading' => ['type' => 'string', 'max' => 160],
                    'metric_ids' => ['type' => 'list', 'default' => []],
                    // Unsourced statistics are a trust risk; the date is shown
                    // beneath each figure.
                    'show_as_of_date' => ['type' => 'boolean', 'default' => true],
                ],
            ),

            // ── Programmes ───────────────────────────────────────────────────
            new BlockDefinition(
                key: 'featured-projects',
                name: 'Featured projects',
                description: 'Project cards, optionally filtered to one division.',
                category: 'Programmes',
                icon: 'heroicon-o-briefcase',
                fields: [
                    'heading' => ['type' => 'string', 'max' => 160],
                    'intro' => ['type' => 'text'],
                    'limit' => ['type' => 'integer', 'default' => 3],
                    'division_id' => ['type' => 'integer'],
                ],
            ),

            new BlockDefinition(
                key: 'gallery',
                name: 'Gallery',
                description: 'A mosaic of photographs.',
                category: 'Media',
                icon: 'heroicon-o-photo',
                fields: [
                    'heading' => ['type' => 'string', 'max' => 160],
                    'gallery_id' => ['type' => 'integer'],
                    'layout' => ['type' => 'string', 'default' => 'mosaic'],
                ],
            ),

            new BlockDefinition(
                key: 'video',
                name: 'Video',
                description: 'Click-to-load video. The player only downloads once a visitor asks for it.',
                category: 'Media',
                icon: 'heroicon-o-play-circle',
                fields: [
                    'heading' => ['type' => 'string', 'max' => 160],
                    'video_url' => ['type' => 'url', 'required' => true],
                    'poster' => ['type' => 'media'],
                    'caption' => ['type' => 'text'],
                ],
            ),

            // ── Social proof ─────────────────────────────────────────────────
            new BlockDefinition(
                key: 'testimonials',
                name: 'Testimonials',
                description: 'Quotes from beneficiaries, volunteers and partners.',
                category: 'Social proof',
                icon: 'heroicon-o-chat-bubble-bottom-center-text',
                fields: [
                    'heading' => ['type' => 'string', 'max' => 160],
                    'limit' => ['type' => 'integer', 'default' => 3],
                    'layout' => ['type' => 'string', 'default' => 'carousel'],
                ],
            ),

            new BlockDefinition(
                key: 'partners',
                name: 'Partner logos',
                description: 'A strip of supporter and partner marks.',
                category: 'Social proof',
                icon: 'heroicon-o-building-office-2',
                fields: [
                    'heading' => ['type' => 'string', 'max' => 160],
                    'grayscale' => ['type' => 'boolean', 'default' => true],
                ],
            ),

            new BlockDefinition(
                key: 'team',
                name: 'People',
                description: 'Trustees, leadership and staff.',
                category: 'Social proof',
                icon: 'heroicon-o-users',
                fields: [
                    'heading' => ['type' => 'string', 'max' => 160],
                    'department_id' => ['type' => 'integer'],
                ],
            ),

            // ── Conversion ───────────────────────────────────────────────────
            new BlockDefinition(
                key: 'cta-band',
                name: 'Call to action band',
                description: 'Full-width band with a heading and a button.',
                category: 'Conversion',
                icon: 'heroicon-o-megaphone',
                fields: [
                    'heading' => ['type' => 'string', 'required' => true, 'max' => 160],
                    'body' => ['type' => 'text'],
                    'cta_label' => ['type' => 'string', 'max' => 40],
                    'cta_url' => ['type' => 'string', 'max' => 500],
                    'background' => ['type' => 'string', 'default' => 'brand'],
                    'image' => ['type' => 'media'],
                ],
            ),

            new BlockDefinition(
                key: 'newsletter',
                name: 'Newsletter sign-up',
                description: 'Email capture with the consent checkbox and double opt-in.',
                category: 'Conversion',
                icon: 'heroicon-o-envelope',
                fields: [
                    'heading' => ['type' => 'string', 'max' => 160],
                    'intro' => ['type' => 'text'],
                    'button_label' => ['type' => 'string', 'default' => 'Subscribe', 'max' => 40],
                ],
                maxPerPage: 1,
            ),

            new BlockDefinition(
                key: 'faq',
                name: 'FAQ accordion',
                description: 'Questions and answers, optionally from one category.',
                category: 'Conversion',
                icon: 'heroicon-o-question-mark-circle',
                fields: [
                    'heading' => ['type' => 'string', 'max' => 160],
                    'intro' => ['type' => 'text'],
                    'category_id' => ['type' => 'integer'],
                    'image' => ['type' => 'media'],
                ],
            ),

            new BlockDefinition(
                key: 'contact-details',
                name: 'Contact details',
                description: 'Address, phone, WhatsApp and email, pulled from settings.',
                category: 'Conversion',
                icon: 'heroicon-o-map-pin',
                fields: [
                    'heading' => ['type' => 'string', 'max' => 160],
                    'show_map' => ['type' => 'boolean', 'default' => false],
                ],
            ),
        ];
    }
}
