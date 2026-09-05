<?php

declare(strict_types=1);

namespace App\Filament\Resources\Testimonials\Schemas;

use App\Filament\Support\MediaPicker;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * A quote from somebody the foundation works with.
 *
 * ── Consent is a field, not a footnote ──────────────────────────────────────
 *
 * A testimonial from a beneficiary is a story about a real person, often a
 * vulnerable one and sometimes a child, published under their name and next to
 * their photograph. Under Act 843 that needs their consent, and the schema has
 * carried `has_consent` and `consent_date` since Phase 3 for this reason.
 *
 * The model refuses to publish without it. This form makes the refusal
 * legible before somebody hits save rather than after: the publish toggle is
 * disabled until consent is recorded, and it says why.
 */
class TestimonialForm
{
    /** @return array<string, string> */
    public static function authorTypes(): array
    {
        return [
            'beneficiary' => __('Someone we support'),
            'volunteer' => __('A volunteer'),
            'partner' => __('A partner'),
            'donor' => __('A donor'),
            'staff' => __('Staff or a trustee'),
        ];
    }

    /**
     * Whether this kind of author needs recorded consent before publication.
     *
     * The same list as `Testimonial::booted()`, and it has to stay that way —
     * a form stricter than the model makes a legitimate testimonial
     * unpublishable, and a form looser than the model produces a save that
     * throws with no field to point at.
     */
    public static function needsConsent(string $authorType): bool
    {
        return in_array($authorType, ['beneficiary', 'volunteer', 'donor'], true);
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('The quote'))->schema([
                Textarea::make('quote')
                    ->label(__('What they said'))
                    ->required()
                    ->rows(4)
                    ->helperText(__('Their words. Tidy the punctuation if you must, but do not write it for them.')),

                Grid::make(2)->schema([
                    TextInput::make('author_name')
                        ->label(__('Name'))
                        ->required()
                        ->maxLength(191),

                    Select::make('author_type')
                        ->label(__('Who they are'))
                        ->options(static::authorTypes())
                        ->default('beneficiary')
                        ->required()
                        ->live(),
                ]),

                Grid::make(2)->schema([
                    TextInput::make('author_role')
                        ->label(__('Role'))
                        ->maxLength(191)
                        ->helperText(__('Optional. "Parent of two", "Head teacher".')),

                    TextInput::make('author_location')
                        ->label(__('Where'))
                        ->maxLength(191)
                        ->helperText(__('A town or district. Not an address.')),
                ]),
            ]),

            Section::make(__('Consent'))
                ->description(__('Required before this can be published.'))
                ->schema([
                    Toggle::make('has_consent')
                        ->label(__('They have agreed to this being published'))
                        ->live()
                        ->helperText(__(
                            'Consent to publish their words, their name and their photograph — under the '
                            .'Data Protection Act, and separately from any consent given for the '
                            .'programme itself. Keep the signed form.'
                        )),

                    DatePicker::make('consent_date')
                        ->label(__('Given on'))
                        ->maxDate(now())
                        ->required(fn (Get $get): bool => (bool) $get('has_consent'))
                        ->visible(fn (Get $get): bool => (bool) $get('has_consent')),
                ]),

            Section::make(__('Photograph and publishing'))->schema([
                MediaPicker::image('photo_id')
                    ->label(__('Photograph'))
                    ->helperText(__(
                        'Optional, and only with the same consent as the quote. Only images that are '
                        .'ready to publish appear here — one is missing if it has no alt text, or if '
                        .'its camera metadata has not been removed. A photograph of somebody\'s home '
                        .'carries the coordinates of it.'
                    )),

                Grid::make(3)->schema([
                    Toggle::make('is_published')
                        ->label(__('Show on the site'))
                        /*
                         * Refused rather than offered-and-rejected. The model
                         * throws on a publish without consent, and a toggle
                         * that can be switched on only to fail on save is a
                         * worse experience than one that explains itself.
                         *
                         * The exemption matches the model's exactly, and is not
                         * a convenience: a partner organisation or a staff
                         * member quoted in a professional capacity is speaking
                         * for themselves. A beneficiary, a volunteer or a donor
                         * is not.
                         */
                        ->disabled(fn (Get $get): bool => static::needsConsent((string) $get('author_type'))
                            && ! $get('has_consent'))
                        ->helperText(fn (Get $get): string => (static::needsConsent((string) $get('author_type'))
                            && ! $get('has_consent'))
                                ? __('Record their consent first.')
                                : __('Visible to everybody.')),

                    Toggle::make('is_featured')
                        ->label(__('Feature'))
                        ->helperText(__('Featured quotes lead the testimonials block.')),

                    TextInput::make('sort_order')
                        ->label(__('Order'))
                        ->numeric()
                        ->default(0),
                ]),
            ]),
        ]);
    }
}
