<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProjectUpdates\Schemas;

use App\Filament\Support\MediaPicker;
use App\Models\Project;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/**
 * An update on a project.
 *
 * A mini-blog per project. It is what turns a project page from a statement of
 * intent into a record of work — and it is the part a funder reads before
 * deciding whether the last grant went anywhere.
 */
class ProjectUpdateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('project_id')
                ->label(__('Which project'))
                ->options(fn (): array => Project::query()->orderBy('title')->pluck('title', 'id')->all())
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
