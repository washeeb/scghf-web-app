<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pages\Schemas;

use App\Blocks\BlockRegistry;
use App\Blocks\SectionSettings;
use App\Enums\PageStatus;
use App\Filament\Blocks\BlockFieldFactory;
use App\Filament\Support\SeoFields;
use App\Models\Page;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/**
 * Building a page.
 *
 * ── Three tabs, in the order somebody actually works ────────────────────────
 *
 * Content first, because that is what they came to do. Settings second.
 * Search last, because it is the part nobody opens until launch and putting it
 * first makes every edit feel like filling in a form.
 *
 * ── The block list is generated, not written ────────────────────────────────
 *
 * Every block in `BlockRegistry` appears here with its own fields, produced by
 * `BlockFieldFactory` from the same field list that generates the block's
 * validation rules. A block added to the registry needs no admin work at all —
 * which is what keeps twenty block types maintainable, and what stops the form
 * and the validator from ever disagreeing.
 */
class PageForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make()->tabs([

                Tabs\Tab::make(__('Content'))->schema([
                    TextInput::make('title')
                        ->label(__('Page title'))
                        ->required()
                        ->maxLength(191)
                        ->live(onBlur: true)
                        /*
                         * The slug follows the title only while the page is a
                         * draft. Once it is published the address is in
                         * somebody's newsletter, and silently changing it on a
                         * retitle would break every link to it — the redirect
                         * manager exists for a deliberate move, not an
                         * accidental one.
                         */
                        ->afterStateUpdated(function (?string $state, callable $set, ?Page $record): void {
                            if ($record?->exists && $record->status !== PageStatus::Draft) {
                                return;
                            }

                            $set('slug', Str::slug((string) $state));
                        })
                        ->helperText(__('What appears as the heading and in the browser tab.')),

                    TextInput::make('slug')
                        ->label(__('Address'))
                        ->required()
                        ->maxLength(191)
                        ->helperText(__('The last part of the web address. Changing it on a published page breaks existing links — add a redirect if you must.')),

                    Textarea::make('excerpt')
                        ->label(__('Short summary'))
                        ->rows(2)
                        ->maxLength(500)
                        ->helperText(__('One or two sentences. Used in listings and as the fallback description in search results.')),

                    self::sections(),
                ]),

                Tabs\Tab::make(__('Settings'))->schema([
                    Grid::make(2)->schema([
                        Select::make('status')
                            ->label(__('Status'))
                            ->options(PageStatus::options())
                            ->required()
                            ->live()
                            ->helperText(__('Only published pages are visible to the public.')),

                        DateTimePicker::make('published_at')
                            ->label(__('Publish at'))
                            ->seconds(false)
                            ->helperText(__('Leave empty to publish immediately. A future date holds it until then.')),
                    ]),

                    Select::make('parent_id')
                        ->label(__('Sits under'))
                        ->relationship('parent', 'title', fn ($query, ?Page $record) => $query
                            // A page cannot be its own parent, and cannot be
                            // parented to one of its own children — either
                            // makes the path builder walk in a circle.
                            ->when($record, fn ($q) => $q->whereKeyNot($record->getKey())))
                        ->searchable()
                        ->helperText(__('Adds this page beneath another in the address — /about/our-story.')),

                    Grid::make(2)->schema([
                        Toggle::make('show_in_sitemap')
                            ->label(__('List in the sitemap'))
                            ->helperText(__('Tells search engines the page exists.')),

                        Toggle::make('show_in_search')
                            ->label(__('Include in site search'))
                            ->helperText(__('Whether visitors can find this page from the search box.')),
                    ]),
                ]),

                SeoFields::tab(),
            ])->columnSpanFull(),
        ]);
    }

    /**
     * The page's blocks.
     *
     * `relationship()` maps this to `page_sections` rows rather than to a JSON
     * column, which is what gives each block its own id — and an id is what
     * lets a revision restore put the right content back in the right place.
     */
    private static function sections(): Repeater
    {
        $registry = app(BlockRegistry::class);
        $factory = app(BlockFieldFactory::class);

        return Repeater::make('sections')
            ->label(__('Blocks on this page'))
            ->relationship()
            ->orderColumn('sort_order')
            ->reorderable()
            ->collapsible()
            ->collapsed()
            ->cloneable()
            ->defaultItems(0)
            ->addActionLabel(__('Add a block'))
            /*
             * The collapsed label has to say which block this is, because a
             * page of eight collapsed rows all reading "Section" is a page
             * somebody has to open eight times to find anything.
             */
            ->itemLabel(function (array $state) use ($registry): string {
                $type = $state['block_type'] ?? null;
                $name = $state['name'] ?? null;

                $blockName = $type !== null && $registry->has($type)
                    ? $registry->get($type)->name
                    : __('Unknown block');

                return $name ? $name.' — '.$blockName : $blockName;
            })
            ->schema([
                Select::make('block_type')
                    ->label(__('Block'))
                    ->options(fn (): array => collect($registry->all())
                        ->mapWithKeys(fn ($definition): array => [$definition->key => $definition->name])
                        ->all())
                    ->required()
                    ->live()
                    ->searchable()
                    /*
                     * Deliberately NOT disabled after the first save.
                     *
                     * It was, on the reasoning that changing the type leaves
                     * `data` shaped for the old block. The reasoning was right
                     * and the mechanism was wrong: Filament omits disabled
                     * fields from the submitted state, so disabling this made
                     * it impossible to add a block from the edit screen at all
                     * — every new row arrived with no type and failed on
                     * insert.
                     *
                     * The mismatch it was guarding against is handled where it
                     * belongs instead. `PageSection` resets `data` to the new
                     * block's defaults when the type changes, so the row can
                     * never hold fields shaped for a block it is no longer —
                     * and a revision is taken before every save, so an editor
                     * who changes a type by accident can put it back.
                     */
                    ->helperText(fn (?string $state): ?string => $state !== null && $registry->has($state)
                        ? $registry->get($state)->description
                        : __('Pick the kind of block. Changing it later clears what you have typed into it.')),

                TextInput::make('name')
                    ->label(__('Label for you'))
                    ->maxLength(191)
                    ->helperText(__('Only shown here, to tell blocks apart in this list. Visitors never see it.')),

                /*
                 * The fields for whichever block is chosen.
                 *
                 * Every block's fields are declared, and the ones that do not
                 * apply are hidden rather than absent — so switching a fresh
                 * block's type shows the right fields immediately, without a
                 * save in between.
                 */
                ...collect($registry->all())
                    ->map(fn ($definition) => Grid::make(1)
                        ->schema($factory->fieldsFor($definition))
                        ->visible(fn (callable $get): bool => $get('block_type') === $definition->key))
                    ->values()
                    ->all(),

                Section::make(__('How it looks'))
                    ->description(__('Leave these alone and the block uses the site defaults, which is usually right.'))
                    ->collapsed()
                    ->schema(self::presentationFields()),
            ]);
    }

    /**
     * Background, spacing, width, alignment, and where it shows.
     *
     * The options come from `SectionSettings::options()`, which is generated
     * from the same constants the renderer looks values up in — so a background
     * offered here is by construction one the renderer knows how to draw, and
     * neither list can grow without the other.
     *
     * @return array<int, mixed>
     */
    private static function presentationFields(): array
    {
        $options = SectionSettings::options();

        return [
            Grid::make(2)->schema([
                Select::make('settings.background')
                    ->label(__('Background'))
                    ->options($options['background'])
                    ->default('none'),

                Select::make('settings.padding')
                    ->label(__('Space above and below'))
                    ->options($options['padding'])
                    ->default('medium'),

                Select::make('settings.width')
                    ->label(__('Width'))
                    ->options($options['width'])
                    ->default('default'),

                Select::make('settings.alignment')
                    ->label(__('Text alignment'))
                    ->options($options['alignment'])
                    ->default('left'),
            ]),

            Select::make('settings.visibility')
                ->label(__('Show this block'))
                ->options($options['visibility'])
                ->default('all')
                ->helperText(__(
                    'Hiding a block on phones hides it visually only — it is still read by screen '
                    .'readers and still found by search engines. Use it for emphasis, never to '
                    .'give different people different information.'
                )),

            Toggle::make('settings.dark_variant')
                ->label(__('Always use the dark palette for this block'))
                ->helperText(__('For a single dark band on an otherwise light page.')),

            Grid::make(2)->schema([
                DateTimePicker::make('visible_from')
                    ->label(__('Show from'))
                    ->seconds(false)
                    ->helperText(__('Optional. For an appeal that should appear on a date.')),

                DateTimePicker::make('visible_until')
                    ->label(__('Hide after'))
                    ->seconds(false)
                    ->helperText(__('Optional. The block disappears by itself — nobody has to remember.')),
            ]),

            Toggle::make('is_visible')
                ->label(__('Visible'))
                ->default(true)
                ->helperText(__('Turn off to hide a block without deleting it.')),
        ];
    }
}
