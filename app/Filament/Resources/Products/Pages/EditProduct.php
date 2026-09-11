<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Models\Product;
use App\Models\ProductVariant;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use RuntimeException;

/**
 * Editing a product, and the two things a form must not do by itself.
 *
 * ── Stock is a ledger ───────────────────────────────────────────────────────
 *
 * "Adjust stock" writes an inventory movement with a reason — a restock from
 * a delivery, or a correction after a stock take with a note saying why. A
 * number typed over another number is a movement with no reason, which in a
 * ledger is indistinguishable from a loss.
 *
 * ── A regulatory review is a recorded decision ──────────────────────────────
 *
 * A product that trips the FDA keyword screen cannot be published until
 * somebody records that it was looked at, with a reference: the FDA
 * correspondence, the licence number, the board minute. "We looked at it" is
 * not a review, and an auditor asking which correspondence covers this
 * product needs an answer.
 */
class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->adjustStockAction(),
            $this->regulatoryReviewAction(),
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        /** @var Product $product */
        $product = $this->getRecord();

        if ($product->unpublishedByScreening) {
            Notification::make()
                ->title(__('Taken off sale pending a regulatory review'))
                ->body(__(
                    'The text mentions :flags, which may fall under FDA Ghana regulation. The product is saved '
                    .'but not on sale until a review is recorded.',
                    ['flags' => implode(', ', $product->regulatoryFlags())],
                ))
                ->warning()
                ->persistent()
                ->send();
        }
    }

    private function adjustStockAction(): Action
    {
        return Action::make('adjustStock')
            ->label(__('Adjust stock'))
            ->icon('heroicon-o-archive-box')
            ->visible(fn (): bool => $this->getRecord()->variants()->where('tracks_stock', true)->exists())
            ->schema([
                Select::make('variant')
                    ->label(__('Which option'))
                    ->options(fn (): array => $this->getRecord()->variants()
                        ->where('tracks_stock', true)
                        ->get()
                        ->mapWithKeys(fn (ProductVariant $v): array => [
                            $v->getKey() => trim(($v->name ?: __('Standard')).' — '.$v->sku).' ('.$v->sellableQuantity().' '.__('available').')',
                        ])
                        ->all())
                    ->required(),

                Radio::make('kind')
                    ->label(__('What happened'))
                    ->options([
                        'restock' => __('Stock arrived'),
                        'adjust' => __('A correction after counting'),
                    ])
                    ->default('restock')
                    ->required()
                    ->live(),

                TextInput::make('quantity')
                    ->label(fn (callable $get): string => $get('kind') === 'adjust' ? __('Change by') : __('How many'))
                    ->numeric()
                    ->integer()
                    ->required()
                    ->helperText(fn (callable $get): string => $get('kind') === 'adjust'
                        ? __('Negative to reduce: −3 if three are missing.')
                        : __('A positive number.')),

                Textarea::make('note')
                    ->label(__('Why'))
                    ->rows(2)
                    ->required(fn (callable $get): bool => $get('kind') === 'adjust')
                    ->helperText(__('Required for a correction. An unexplained adjustment is indistinguishable from a loss.')),
            ])
            ->action(function (array $data): void {
                /** @var ProductVariant $variant */
                $variant = $this->getRecord()->variants()->findOrFail($data['variant']);

                try {
                    if ($data['kind'] === 'adjust') {
                        $variant->adjust((int) $data['quantity'], (string) $data['note'], auth()->user());
                    } else {
                        $variant->restock((int) $data['quantity'], (string) ($data['note'] ?? ''), auth()->user());
                    }
                } catch (RuntimeException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()
                    ->title(__(':sku now has :count available.', ['sku' => $variant->sku, 'count' => $variant->fresh()->sellableQuantity()]))
                    ->success()
                    ->send();

                $this->getRecord()->refresh();
                $this->fillForm();
            });
    }

    private function regulatoryReviewAction(): Action
    {
        return Action::make('regulatoryReview')
            ->label(__('Record regulatory review'))
            ->icon('heroicon-o-clipboard-document-check')
            ->color('danger')
            ->visible(fn (): bool => $this->getRecord()->needsRegulatoryReview())
            ->modalHeading(__('Record that this product was reviewed'))
            ->modalDescription(fn (): string => __(
                'It was flagged for: :flags. Recording a review says a person with the authority to decide '
                .'has looked at it — it does not say the product is exempt. Keep the correspondence.',
                ['flags' => implode(', ', $this->getRecord()->regulatoryFlags())],
            ))
            ->schema([
                TextInput::make('reference')
                    ->label(__('Reference'))
                    ->required()
                    ->maxLength(191)
                    ->helperText(__('The FDA letter, the licence number, or the board minute that authorised it.')),
            ])
            ->action(function (array $data): void {
                $this->getRecord()->recordRegulatoryReview(auth()->user(), (string) $data['reference']);

                Notification::make()->title(__('Review recorded. The product can now be published.'))->success()->send();

                $this->getRecord()->refresh();
                $this->fillForm();
            });
    }
}
