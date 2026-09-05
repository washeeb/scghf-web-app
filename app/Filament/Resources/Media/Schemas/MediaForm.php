<?php

declare(strict_types=1);

namespace App\Filament\Resources\Media\Schemas;

use App\Models\Media;
use App\Models\MediaFolder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

/**
 * Describing a file that already exists.
 *
 * The file itself is not editable here — replacing it is a separate action with
 * its own confirmation, because it changes what thirty other records display
 * without changing any of them.
 */
class MediaForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([

            Section::make(__('How this file is described'))
                ->description(__('Alt text is what a screen reader announces, and what shows if the image fails to load.'))
                ->schema([

                    Textarea::make('alt_text')
                        ->label(__('Alt text'))
                        ->rows(2)
                        ->maxLength(500)
                        /*
                         * Required unless the image is marked decorative.
                         *
                         * Not "recommended". `Media::isPublishable()` refuses a
                         * file without it, so a form that let this be saved
                         * empty would be a form that produces files nobody can
                         * use — and the person would find out later, on a
                         * different screen, with no explanation.
                         */
                        ->requiredUnless('decorative', true)
                        ->helperText(new HtmlString(__(
                            'Describe what the image <strong>shows</strong>, not that it is a photograph. '
                            .'"Ama receiving her school kit" — not "photo of a girl".'
                        ))),

                    Toggle::make('decorative')
                        ->label(__('Purely decorative'))
                        ->helperText(__(
                            'A border, a texture, a flourish. A decorative image is given an empty alt '
                            .'attribute so screen readers skip it — which is right for decoration and '
                            .'wrong for anything carrying meaning. If in doubt, it is not decorative.'
                        ))
                        ->live()
                        // Stored in `custom_properties` rather than as a column,
                        // because that is where spatie keeps per-file flags and
                        // `Media::isDecorative()` already reads it from there.
                        ->afterStateHydrated(fn (Toggle $component, ?Media $record) => $component->state(
                            $record?->getCustomProperty('decorative', false) === true
                        ))
                        ->dehydrated(false),

                    TextInput::make('caption')
                        ->label(__('Caption'))
                        ->maxLength(500)
                        ->helperText(__('Shown under the image on the page. Optional.')),

                    TextInput::make('credit')
                        ->label(__('Credit'))
                        ->maxLength(191)
                        ->helperText(__(
                            'Who took it. Donated photography usually comes with a condition that it is '
                            .'credited, and this is the field that keeps that promise.'
                        )),
                ]),

            Section::make(__('Filing'))
                ->schema([
                    Select::make('folder_id')
                        ->label(__('Folder'))
                        ->relationship('folder', 'name')
                        ->options(fn (): array => MediaFolder::orderBy('path')->pluck('name', 'id')->all())
                        ->searchable()
                        ->helperText(__(
                            'Beneficiary photographs belong in Beneficiaries — consent rules are keyed '
                            .'to that folder.'
                        )),
                ]),

            Section::make(__('Status'))
                ->description(__('None of this is editable. Whether a file has been sanitised is a fact about the bytes on disk, and a form field would invite somebody to assert it instead.'))
                ->schema([

                    TextEntry::make('publishable')
                        ->label(__('Can this be published?'))
                        ->state(fn (?Media $record): string => $record?->publicationRejectionReason()
                            ?? __('Yes — this file can be used on a page.'))
                        ->color(fn (?Media $record): string => $record?->isPublishable() ? 'success' : 'warning'),

                    TextEntry::make('metadata')
                        ->label(__('Camera metadata'))
                        ->state(fn (?Media $record): string => match (true) {
                            $record === null => '—',
                            $record->sanitisation_error !== null => __('Failed: :error', ['error' => $record->sanitisation_error]),
                            $record->metadata_stripped_at === null => __('Not checked yet'),
                            default => __('Removed on :date', ['date' => $record->metadata_stripped_at->format('j F Y, H:i')]),
                        })
                        ->color(fn (?Media $record): string => $record?->hasBeenSanitised() ? 'success' : 'danger'),

                    TextEntry::make('gps')
                        ->label(__('Arrived carrying a location'))
                        /*
                         * Worth its own line rather than a footnote. One
                         * photograph with coordinates in it is a stripped file;
                         * a run of them means somebody is photographing
                         * beneficiaries on a phone with location services on,
                         * which is a conversation rather than a cleanup.
                         */
                        ->state(fn (?Media $record): string => $record?->had_gps_data
                            ? __('Yes — the location has been removed from the file')
                            : __('No'))
                        ->color(fn (?Media $record): string => $record?->had_gps_data ? 'warning' : 'gray'),

                    TextEntry::make('file')
                        ->label(__('File'))
                        ->state(fn (?Media $record): string => $record === null ? '—' : trim(sprintf(
                            '%s · %s%s',
                            strtoupper((string) pathinfo((string) $record->file_name, PATHINFO_EXTENSION)),
                            number_format((int) $record->size / 1024).' KB',
                            $record->width() ? ' · '.$record->width().'×'.$record->height().' px' : '',
                        ))),

                    TextEntry::make('conversions')
                        ->label(__('Sizes generated'))
                        /*
                         * Shown because its absence has a cause worth reading.
                         * A file with no conversions is either brand new — the
                         * queue runs on a cron tick — or unsanitised, in which
                         * case it will never get any until that is fixed.
                         */
                        ->state(function (?Media $record): string {
                            $generated = array_keys(array_filter((array) $record?->generated_conversions));

                            return match (true) {
                                $generated !== [] => implode(', ', $generated),
                                $record === null => '—',
                                ! $record->hasBeenSanitised() => __('None, and none will be made until the metadata is removed.'),
                                default => __('None yet — they are generated on the next scheduled run.'),
                            };
                        }),

                    TextEntry::make('inodes')
                        ->label(__('Files on disk'))
                        // The unit that runs out first on shared hosting.
                        ->state(fn (?Media $record): string => $record === null
                            ? '—'
                            : trans_choice('1 file|:count files', $record->inodeCost(), ['count' => $record->inodeCost()])),
                ])
                ->columns(2),
        ]);
    }
}
