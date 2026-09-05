<?php

declare(strict_types=1);

namespace App\Filament\Resources\Galleries\Schemas;

use App\Filament\Support\MediaPicker;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/**
 * An album of photographs.
 *
 * ── Consent gates the album, not each photograph ────────────────────────────
 *
 * A gallery from a school visit or a food distribution is a set of photographs
 * of identifiable people, frequently children. Consent is recorded once for the
 * album because that is how it is actually collected — a signed form covering
 * the event — and the publish toggle is refused without it.
 *
 * That sits on top of, not instead of, the per-file gate: `MediaPicker` still
 * refuses any image whose camera metadata has not been stripped, because a
 * photograph taken outside somebody's home carries the coordinates of it.
 */
class GalleryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('The album'))->schema([
                Grid::make(2)->schema([
                    TextInput::make('title')
                        ->label(__('Title'))
                        ->required()
                        ->maxLength(191)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (?string $state, callable $set) => $set('slug', Str::slug((string) $state))),

                    TextInput::make('slug')
                        ->label(__('Address'))
                        ->required()
                        ->maxLength(191)
                        ->unique(ignoreRecord: true),
                ]),

                Textarea::make('description')
                    ->label(__('Description'))
                    ->rows(3),

                Grid::make(2)->schema([
                    DatePicker::make('taken_on')
                        ->label(__('When'))
                        ->maxDate(now()),

                    TextInput::make('location')
                        ->label(__('Where'))
                        ->maxLength(191)
                        ->helperText(__('A town, district or the name of a school. Not a street address — the album is public.')),
                ]),
            ]),

            Section::make(__('Photographs'))->schema([
                MediaPicker::image('cover_id')
                    ->label(__('Cover image'))
                    ->helperText(__('Shown on the galleries page. Leave empty to use the first photograph.')),

                Repeater::make('items')
                    ->label(__('In this album'))
                    ->relationship()
                    ->orderColumn('sort_order')
                    ->reorderable()
                    ->collapsible()
                    ->addActionLabel(__('Add a photograph'))
                    ->itemLabel(fn (array $state): ?string => $state['caption'] ?? null)
                    ->schema([
                        MediaPicker::image('media_id')
                            ->label(__('Photograph'))
                            ->required(),

                        TextInput::make('caption')
                            ->label(__('Caption'))
                            ->maxLength(500)
                            /*
                             * Not the same thing as alt text, and worth saying
                             * so. Alt text describes the image for somebody who
                             * cannot see it and lives on the file in the media
                             * library; a caption is editorial and is read by
                             * everybody. Conflating them produces galleries
                             * where the caption is "photo of children".
                             */
                            ->helperText(__('Shown under the photograph. Its alt text — the description read aloud to somebody who cannot see it — lives on the file in the media library.')),
                    ]),
            ]),

            Section::make(__('Consent and publishing'))->schema([
                Toggle::make('has_consent')
                    ->label(__('Everybody identifiable in these photographs has consented'))
                    ->live()
                    ->helperText(__(
                        'Including the parent or guardian of any child. Keep the signed forms. '
                        .'This is a Data Protection Act obligation, not a formality.'
                    )),

                Grid::make(2)->schema([
                    Toggle::make('is_published')
                        ->label(__('Show on the site'))
                        ->disabled(fn (Get $get): bool => ! $get('has_consent'))
                        ->helperText(fn (Get $get): string => $get('has_consent')
                            ? __('Visible to everybody.')
                            : __('Record consent first.')),

                    TextInput::make('sort_order')
                        ->label(__('Order'))
                        ->numeric()
                        ->default(0),
                ]),
            ]),
        ]);
    }
}
