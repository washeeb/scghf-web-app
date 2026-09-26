<?php

declare(strict_types=1);

namespace App\Filament\Resources\Faqs\Schemas;

use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/**
 * One question and its answer.
 *
 * The question field is a textarea rather than a single line, because a real
 * FAQ question is a sentence — "Can I give in memory of somebody?" — and a
 * one-line input encourages the keyword-shaped headings ("Memorial giving")
 * that make an FAQ page useless to the person actually asking.
 */
class FaqForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Textarea::make('question')
                ->label(__('Question'))
                ->required()
                ->rows(2)
                ->maxLength(500)
                ->helperText(__('Write it the way somebody would ask it, not as a heading.')),

            RichEditor::make('answer')
                ->label(__('Answer'))
                ->required()
                ->columnSpanFull(),

            Grid::make(2)->schema([
                Select::make('faq_category_id')
                    ->label(__('Category'))
                    ->relationship('category', 'name')
                    ->searchable()
                    ->preload()
                    ->createOptionForm([
                        TextInput::make('name')
                            ->label(__('Name'))
                            ->required()
                            ->maxLength(191)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (?string $state, callable $set) => $set('slug', Str::slug((string) $state))),
                        TextInput::make('slug')->label(__('Address'))->required()->maxLength(191),
                    ])
                    ->helperText(__('Questions are grouped under their category on the FAQ page.')),

                TextInput::make('sort_order')
                    ->label(__('Order'))
                    ->numeric()
                    ->default(0)
                    ->helperText(__('Lower numbers come first, within the category.')),
            ]),

            Grid::make(2)->schema([
                Toggle::make('is_published')
                    ->label(__('Show on the site'))
                    ->default(true),

                Toggle::make('is_featured')
                    ->label(__('Feature this question'))
                    ->helperText(__('Featured questions can be pulled onto another page — the donation page, say — without repeating the answer.')),
            ]),
        ]);
    }
}
