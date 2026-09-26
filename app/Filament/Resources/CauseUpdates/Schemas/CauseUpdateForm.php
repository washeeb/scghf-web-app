<?php

declare(strict_types=1);

namespace App\Filament\Resources\CauseUpdates\Schemas;

use App\Filament\Support\MediaPicker;
use App\Models\Cause;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/**
 * An update on an appeal.
 *
 * Publishing one is only half of it — the other half is the "tell the donors"
 * action on the list, which emails the people who actually funded this appeal.
 * The commonest reason a donor does not give a second time is that they never
 * heard what the first gift did.
 */
class CauseUpdateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('cause_id')
                ->label(__('Which appeal'))
                ->options(fn (): array => Cause::query()->orderBy('title')->pluck('title', 'id')->all())
                ->searchable()
                ->required(),

            Grid::make(2)->schema([
                TextInput::make('title')
                    ->label(__('Headline'))
                    ->required()
                    ->maxLength(191)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (?string $state, callable $set) => $set('slug', Str::slug((string) $state))),

                TextInput::make('slug')
                    ->label(__('Address'))
                    ->required()
                    ->maxLength(191),
            ]),

            RichEditor::make('body')
                ->label(__('What happened'))
                ->required()
                ->columnSpanFull()
                ->helperText(__('Specific and concrete. "The borehole at Zorko was capped on Tuesday" is worth more than "work continues".')),

            MediaPicker::image('image_id')->label(__('Photograph')),

            Grid::make(2)->schema([
                Toggle::make('is_published')->label(__('Show on the site')),

                DateTimePicker::make('published_at')
                    ->label(__('Publish at'))
                    ->seconds(false)
                    ->helperText(__('Leave empty to publish immediately.')),
            ]),
        ]);
    }
}
