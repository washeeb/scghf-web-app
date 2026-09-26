<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;

/**
 * "Search & sharing" — one section, every content type.
 *
 * `seo_meta` has been polymorphic since Phase 3 and the Page form was the
 * only one that reached it, with three of its eleven columns. This is the
 * whole set, with the character counts search engines and share cards
 * actually cut at, on every model that carries `HasSeo`.
 *
 * Everything is optional. Empty means the fallbacks: the record's own
 * title and summary, the site's default share image, the page's own URL
 * as its canonical.
 */
final class SeoFields
{
    private const TITLE_IDEAL = 60;

    private const DESCRIPTION_IDEAL = 155;

    public static function tab(): Tab
    {
        return Tab::make(__('Search & sharing'))->schema([self::section()]);
    }

    public static function section(): Section
    {
        return Section::make(__('How this appears in Google and when shared'))
            ->description(__('All optional. Left empty, the title and summary are used, with the site’s default share image.'))
            ->relationship('seo')
            ->collapsible()
            ->schema([
                TextInput::make('title')
                    ->label(__('Search result title'))
                    ->maxLength(191)
                    ->live(onBlur: true)
                    ->helperText(fn (Get $get): string => self::count($get('title'), self::TITLE_IDEAL, __('Google shows about :n characters; longer is cut off with an ellipsis.', ['n' => self::TITLE_IDEAL]))),

                Textarea::make('description')
                    ->label(__('Search result description'))
                    ->rows(2)
                    ->maxLength(500)
                    ->live(onBlur: true)
                    ->helperText(fn (Get $get): string => self::count($get('description'), self::DESCRIPTION_IDEAL, __('The sentence under the link. About :n characters shows; write it as a reason to click.', ['n' => self::DESCRIPTION_IDEAL]))),

                Grid::make(2)->schema([
                    Toggle::make('no_index')
                        ->label(__('Hide from search engines'))
                        ->helperText(__('For thank-you pages and anything not meant to be found. Site-wide indexing must also be on in Settings → Search engines.')),
                    Toggle::make('no_follow')
                        ->label(__('Do not follow links on this page')),
                ]),

                TextInput::make('canonical_url')
                    ->label(__('Canonical URL'))
                    ->url()
                    ->maxLength(500)
                    ->helperText(__('Only when this content is a copy of something published elsewhere first. Otherwise leave empty: the page’s own address is the canonical.')),

                Section::make(__('When shared on WhatsApp, Facebook, X'))
                    ->collapsed()
                    ->schema([
                        TextInput::make('og_title')
                            ->label(__('Share title'))
                            ->maxLength(191)
                            ->live(onBlur: true)
                            ->helperText(fn (Get $get): string => self::count($get('og_title'), 70, __('Defaults to the search result title. Cards show about 70 characters.'))),
                        Textarea::make('og_description')
                            ->label(__('Share text'))
                            ->rows(2)
                            ->maxLength(300)
                            ->live(onBlur: true)
                            ->helperText(fn (Get $get): string => self::count($get('og_description'), 200, __('Defaults to the search result description. About 200 characters shows.'))),
                        MediaPicker::image('og_image_id')
                            ->label(__('Share image'))
                            ->helperText(__('1200 × 630 works everywhere. Defaults to the featured image, then the site’s default share image.')),
                        Grid::make(2)->schema([
                            Select::make('og_type')
                                ->label(__('Type'))
                                ->options(['website' => __('Web page'), 'article' => __('Article'), 'product' => __('Product'), 'event' => __('Event'), 'profile' => __('Profile')])
                                ->default('website'),
                            Select::make('twitter_card')
                                ->label(__('X card'))
                                ->options(['summary_large_image' => __('Large image'), 'summary' => __('Small image')])
                                ->default('summary_large_image'),
                        ]),
                    ]),
            ]);
    }

    private static function count(mixed $value, int $ideal, string $advice): string
    {
        $length = mb_strlen(trim((string) $value));

        if ($length === 0) {
            return $advice;
        }

        return $length > $ideal
            ? __(':length characters — :over over the :ideal that shows.', ['length' => $length, 'over' => $length - $ideal, 'ideal' => $ideal])
            : __(':length of about :ideal characters.', ['length' => $length, 'ideal' => $ideal]);
    }
}
