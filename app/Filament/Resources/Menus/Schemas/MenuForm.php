<?php

declare(strict_types=1);

namespace App\Filament\Resources\Menus\Schemas;

use App\Enums\MenuItemLinkType;
use App\Models\Menu;
use App\Models\Page;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Route;

/**
 * Building a menu.
 *
 * ── Two levels of repeater, because the menu supports two levels ────────────
 *
 * `Menu::max_depth` is 1 — root plus one level of dropdown — and `MenuItem`
 * enforces it on save, for seeders and imports as well as for this form. The
 * nested repeater matches that exactly: items, and children under them. A
 * generic tree widget would let somebody build a third level the layout cannot
 * draw, and the model would then refuse the save with an error they could not
 * have anticipated.
 *
 * ── A link is a relationship, not a URL ─────────────────────────────────────
 *
 * Choosing "a page on this site" stores `page_id`, so the item follows that
 * page when its slug changes. Typing the URL instead is how a site accumulates
 * broken navigation nobody notices — and `MenuItemLinkType` exists precisely to
 * make that the easy path.
 */
class MenuForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('The menu'))->schema([
                Grid::make(2)->schema([
                    TextInput::make('name')
                        ->label(__('Name'))
                        ->required()
                        ->maxLength(191)
                        ->helperText(__('Only shown here.')),

                    TextInput::make('key')
                        ->label(__('Key'))
                        ->required()
                        ->maxLength(64)
                        /*
                         * The layout asks for a menu by key — `header`,
                         * `footer_legal`. Renaming one detaches it from the
                         * template that renders it, silently, so a locked menu
                         * refuses the edit rather than producing a site with no
                         * navigation.
                         */
                        ->disabled(fn (?Menu $record): bool => (bool) $record?->is_locked)
                        ->helperText(__('The name the layout looks this menu up by. Changing it on a menu the site uses would empty that part of the page.')),
                ]),

                TextInput::make('description')
                    ->label(__('What this menu is for'))
                    ->maxLength(191),
            ]),

            Section::make(__('Items'))
                ->description(__('Drag to reorder. Drag onto an item to nest under it.'))
                ->schema([static::items()]),
        ]);
    }

    private static function items(): Repeater
    {
        return Repeater::make('rootItems')
            ->label('')
            ->relationship()
            ->orderColumn('sort_order')
            ->reorderable()
            ->collapsible()
            ->collapsed()
            ->cloneable()
            ->addActionLabel(__('Add an item'))
            ->itemLabel(fn (array $state): ?string => $state['label'] ?? null)
            ->schema([
                ...static::itemFields(),

                Repeater::make('children')
                    ->label(__('Items under this one'))
                    ->relationship()
                    ->orderColumn('sort_order')
                    ->reorderable()
                    ->collapsible()
                    ->collapsed()
                    ->addActionLabel(__('Add a child item'))
                    ->itemLabel(fn (array $state): ?string => $state['label'] ?? null)
                    ->schema(static::itemFields())
                    /*
                     * Hidden on a flat menu rather than shown and refused.
                     * `max_depth` 0 means the layout draws one level, and the
                     * model would throw on save — offering the field and then
                     * rejecting it is a worse experience than not offering it.
                     *
                     * Read off the PAGE rather than off `$record`: inside a
                     * repeater `$record` is the item being drawn, not the menu
                     * it belongs to, so asking a `MenuItem` for its `max_depth`
                     * is a type error at render time.
                     */
                    ->visible(fn (EditRecord $livewire): bool => ($livewire->getRecord()?->max_depth ?? 1) >= 1),
            ]);
    }

    /**
     * The fields common to an item at either level.
     *
     * @return array<int, mixed>
     */
    private static function itemFields(): array
    {
        return [
            Grid::make(2)->schema([
                TextInput::make('label')
                    ->label(__('Label'))
                    ->required()
                    ->maxLength(191)
                    ->helperText(__('What visitors see.')),

                Select::make('link_type')
                    ->label(__('Links to'))
                    ->options(MenuItemLinkType::options())
                    ->default(MenuItemLinkType::Page->value)
                    ->required()
                    ->live(),
            ]),

            Select::make('page_id')
                ->label(__('Page'))
                ->options(fn (): array => Page::query()->orderBy('path')->pluck('title', 'id')->all())
                ->searchable()
                ->visible(fn (Get $get): bool => $get('link_type') === MenuItemLinkType::Page->value)
                ->required(fn (Get $get): bool => $get('link_type') === MenuItemLinkType::Page->value)
                ->helperText(__('The item follows this page if its address changes later.')),

            Select::make('route_name')
                ->label(__('Section'))
                ->options(fn (): array => static::namedRoutes())
                ->searchable()
                ->visible(fn (Get $get): bool => $get('link_type') === MenuItemLinkType::Route->value)
                ->required(fn (Get $get): bool => $get('link_type') === MenuItemLinkType::Route->value)
                ->helperText(__('Built-in parts of the site — the shop, the donation form. A section that has not been built yet is simply not shown to visitors.')),

            TextInput::make('url')
                ->label(__('Web address'))
                ->url()
                ->maxLength(500)
                ->visible(fn (Get $get): bool => $get('link_type') === MenuItemLinkType::External->value)
                ->required(fn (Get $get): bool => $get('link_type') === MenuItemLinkType::External->value)
                ->helperText(__('Opens in a new tab automatically.')),

            Grid::make(2)->schema([
                Select::make('visible_to')
                    ->label(__('Who sees it'))
                    ->options([
                        'all' => __('Everybody'),
                        'guest' => __('Only visitors who are not signed in'),
                        'auth' => __('Only signed-in visitors'),
                    ])
                    ->default('all'),

                Toggle::make('is_visible')
                    ->label(__('Visible'))
                    ->default(true)
                    ->helperText(__('Turn off to hide without deleting.')),
            ]),

            Toggle::make('is_highlighted')
                ->label(__('Show as the highlighted button'))
                ->helperText(__(
                    'The header draws its highlighted item as the Donate button rather than as a '
                    .'link in the list, so a campaign can point it somewhere without a deploy. '
                    .'Only one item should carry this.'
                )),
        ];
    }

    /**
     * The named routes an editor may link to.
     *
     * Filtered to GET routes with a name and no parameters — a menu item cannot
     * supply a `{slug}`, and offering `causes.show` would produce a link that
     * cannot be built. Admin, webhook and account routes are excluded because
     * a public menu has no business pointing at them.
     *
     * @return array<string, string>
     */
    private static function namedRoutes(): array
    {
        $excluded = ['filament', 'webhooks', 'account', 'password', 'verification', 'two-factor'];

        return collect(Route::getRoutes()->getRoutesByName())
            ->filter(fn ($route, string $name): bool => in_array('GET', $route->methods(), true)
                && $route->parameterNames() === []
                && ! collect($excluded)->contains(fn (string $prefix): bool => str_starts_with($name, $prefix)))
            ->keys()
            ->mapWithKeys(fn (string $name): array => [$name => $name])
            ->all();
    }
}
