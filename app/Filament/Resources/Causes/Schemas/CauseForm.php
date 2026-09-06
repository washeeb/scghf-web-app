<?php

declare(strict_types=1);

namespace App\Filament\Resources\Causes\Schemas;

use App\Enums\CauseStatus;
use App\Filament\Support\MediaPicker;
use App\Models\Cause;
use App\Models\Project;
use App\ValueObjects\Money;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/**
 * An appeal.
 *
 * ── The goal is in pesewas, and the form says so ────────────────────────────
 *
 * Every amount in this application is an integer of minor units. A field that
 * quietly accepted "5000" as five thousand cedis would set a goal of fifty —
 * and the live conversion under the field is what makes that impossible to do
 * by accident.
 *
 * ── `raised` is not editable, and is shown anyway ───────────────────────────
 *
 * It is incremented atomically as completed donations land, and correcting it
 * by typing would silently disagree with the donations table — which is the one
 * number a trustee might later have to defend. It is displayed because an
 * editor setting a goal needs to know what has already come in.
 *
 * ── Tax deductibility is a claim, not a checkbox ────────────────────────────
 *
 * `is_tax_deductible` says the trustees consider this a qualifying cause. It
 * does NOT say the foundation holds the GRA approval that makes the claim
 * sayable to a donor — `TaxDeductibility` is the gate for that, and the helper
 * text says so, because the field name alone invites the wrong reading.
 */
class CauseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make()->columnSpanFull()->tabs([

                Tabs\Tab::make(__('The appeal'))->schema([
                    TextInput::make('title')
                        ->label(__('Title'))
                        ->required()
                        ->maxLength(191)
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (?string $state, callable $set, ?Cause $record): void {
                            // A published appeal keeps the address people have
                            // already shared. A slug that follows the title
                            // turns every link in a newsletter into a 404.
                            if ($record?->exists && $record->is_published) {
                                return;
                            }

                            $set('slug', Str::slug((string) $state));
                        }),

                    TextInput::make('slug')
                        ->label(__('Address'))
                        ->required()
                        ->maxLength(191)
                        ->unique(ignoreRecord: true)
                        ->helperText(__('Changing this on a live appeal breaks every link anybody has shared.')),

                    Textarea::make('summary')
                        ->label(__('Summary'))
                        ->rows(2)
                        ->maxLength(500)
                        ->helperText(__('One or two lines. This is what appears on the appeals list and when somebody shares the link.')),

                    RichEditor::make('description')
                        ->label(__('The story'))
                        ->columnSpanFull()
                        ->helperText(__('What the money does, and for whom. Specific beats general — "a school kit for one child" raises more than "education support".')),

                    Select::make('project_id')
                        ->label(__('Supports which project'))
                        ->options(fn (): array => Project::query()->orderBy('title')->pluck('title', 'id')->all())
                        ->searchable()
                        ->helperText(__('Optional. Linking an appeal to a project puts it on that project page and lets a donor see what their gift funds.')),
                ]),

                Tabs\Tab::make(__('The target'))->schema([
                    Grid::make(2)->schema([
                        TextInput::make('goal')
                            ->label(__('Goal'))
                            ->numeric()
                            ->minValue(0)
                            ->prefix(__('pesewas'))
                            ->live(onBlur: true)
                            /*
                             * ⚠ `MoneyCast` accepts a Money or an INTEGER of
                             * minor units and throws on anything else — which
                             * is the right strictness, because it is what stops
                             * a stray '50.00' being written as fifty pesewas.
                             *
                             * A form field submits a string. So the value is
                             * cast on the way out and the Money unwrapped on
                             * the way in; without both, opening this record
                             * renders "GH₵ 5,000.00" into a number field and
                             * saving it throws.
                             */
                            ->formatStateUsing(fn (?Money $state): ?int => $state?->toMinor())
                            ->dehydrateStateUsing(fn (?string $state): ?int => blank($state) ? null : (int) $state)
                            ->helperText(fn ($state): string => __('= :amount. Leave empty for an appeal with no target.', [
                                'amount' => 'GH₵ '.number_format(((int) $state) / 100, 2),
                            ])),

                        TextEntry::make('raised')
                            ->label(__('Raised so far'))
                            ->state(fn (?Cause $record): string => $record?->raisedAmount()->format() ?? 'GH₵ 0.00')
                            ->helperText(__('Counted from completed donations. Not editable here — this is the number the donations table can prove.')),
                    ]),

                    Grid::make(2)->schema([
                        DatePicker::make('starts_on')->label(__('Opens')),

                        DatePicker::make('ends_on')
                            ->label(__('Closes'))
                            ->after('starts_on')
                            ->helperText(__('After this date the appeal stops taking money. An appeal still collecting past its closing date is how a foundation ends up holding funds it announced it had stopped raising.')),
                    ]),

                    Grid::make(2)->schema([
                        Toggle::make('allow_recurring')
                            ->label(__('Allow monthly giving'))
                            ->default(true),

                        Toggle::make('allow_fee_cover')
                            ->label(__('Offer to cover the transaction fee'))
                            ->default(true)
                            ->helperText(__('Most donors say yes, and it is the difference between the appeal receiving the whole gift or the gateway taking its cut from it.')),
                    ]),

                    Toggle::make('is_tax_deductible')
                        ->label(__('The trustees consider this a qualifying cause'))
                        ->helperText(__(
                            'This does NOT put tax wording in front of a donor on its own. That needs a '
                            .'current GRA approval on file — without one the claim is simply not shown, '
                            .'whatever this says.'
                        )),
                ]),

                Tabs\Tab::make(__('Publishing'))->schema([
                    Grid::make(2)->schema([
                        Select::make('status')
                            ->label(__('Status'))
                            ->options(CauseStatus::options())
                            ->default(CauseStatus::Draft->value)
                            ->required()
                            ->helperText(__('Only an active appeal takes donations.')),

                        DateTimePicker::make('published_at')
                            ->label(__('Publish at'))
                            ->seconds(false)
                            ->helperText(__('Leave empty to publish as soon as it is switched on.')),
                    ]),

                    Grid::make(3)->schema([
                        Toggle::make('is_published')->label(__('Show on the site')),
                        Toggle::make('is_featured')->label(__('Feature')),
                        TextInput::make('sort_order')->label(__('Order'))->numeric()->default(0),
                    ]),

                    MediaPicker::image('featured_image_id')
                        ->label(__('Image'))
                        ->helperText(__('Shown on the appeal, in the list, and as the picture when the link is shared.')),
                ]),
            ]),
        ]);
    }
}
