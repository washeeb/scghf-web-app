<?php

declare(strict_types=1);

namespace App\Filament\Resources\Testimonials\Tables;

use App\Filament\Resources\Testimonials\Schemas\TestimonialForm;
use App\Filament\Support\ExportAction;
use App\Models\Testimonial;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The testimonials list.
 *
 * The consent column is first after the quote, and it is not decoration. A
 * testimonial waiting on consent looks exactly like a published one in every
 * other respect, and the difference is the whole legal position — so the list
 * shows it rather than making somebody open each record to find out.
 */
class TestimonialsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                TextColumn::make('author_name')
                    ->label(__('Who'))
                    ->searchable()
                    ->description(fn (Testimonial $record): string => collect([
                        TestimonialForm::authorTypes()[$record->author_type] ?? $record->author_type,
                        $record->author_role,
                        $record->author_location,
                    ])->filter()->implode(' · ')),

                TextColumn::make('quote')
                    ->label(__('Quote'))
                    ->wrap()
                    ->limit(90)
                    ->searchable(),

                TextColumn::make('has_consent')
                    ->label(__('Consent'))
                    ->badge()
                    ->state(fn (Testimonial $record): string => match (true) {
                        $record->has_consent => __('Recorded'),
                        ! TestimonialForm::needsConsent((string) $record->author_type) => __('Not needed'),
                        default => __('Missing'),
                    })
                    ->color(fn (Testimonial $record): string => match (true) {
                        $record->has_consent => 'success',
                        ! TestimonialForm::needsConsent((string) $record->author_type) => 'gray',
                        default => 'danger',
                    })
                    ->description(fn (Testimonial $record): ?string => $record->consent_date?->toFormattedDateString()),

                IconColumn::make('is_published')
                    ->label(__('Shown'))
                    ->boolean(),

                IconColumn::make('is_featured')
                    ->label(__('Featured'))
                    ->boolean()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('author_type')
                    ->label(__('Who they are'))
                    ->options(TestimonialForm::authorTypes()),

                TernaryFilter::make('is_published')->label(__('Shown on the site')),

                Filter::make('awaiting_consent')
                    ->label(__('Waiting on consent'))
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query
                        ->where('has_consent', false)
                        ->whereIn('author_type', ['beneficiary', 'volunteer', 'donor'])),

                TrashedFilter::make(),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                ExportAction::make('report.generated', __('testimonials'), [
                    'Name' => 'author_name',
                    'Who they are' => 'author_type',
                    'Quote' => 'quote',
                    'Consent recorded' => 'has_consent',
                    'Consent date' => 'consent_date',
                    'Shown' => 'is_published',
                ]),
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ]);
    }
}
