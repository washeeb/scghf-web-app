<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pages;

use App\Filament\Resources\Pages\Pages\CreatePage;
use App\Filament\Resources\Pages\Pages\EditPage;
use App\Filament\Resources\Pages\Pages\ListPages;
use App\Filament\Resources\Pages\Schemas\PageForm;
use App\Filament\Resources\Pages\Tables\PagesTable;
use App\Models\Page;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Pages, and the blocks on them.
 *
 * The heart of Phase 5: the screen the foundation's staff run the website from.
 * Everything it offers is a closed vocabulary — a curated block library, a fixed
 * set of backgrounds and spacings — because the alternative is a CMS that goes
 * off-brand and unmaintainable one well-meaning edit at a time. Blueprint §3.2
 * made that decision; this is where it is felt.
 */
class PageResource extends Resource
{
    protected static ?string $model = Page::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $recordTitleAttribute = 'title';

    public static function getNavigationLabel(): string
    {
        return __('Pages');
    }

    public static function form(Schema $schema): Schema
    {
        return PageForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PagesTable::configure($table);
    }

    /**
     * The count of published pages with nothing on them.
     *
     * A published page with no blocks renders as a heading over white space. It
     * is the commonest way a CMS goes live looking broken, and nothing else in
     * the panel would ever mention it.
     */
    public static function getNavigationBadge(): ?string
    {
        $blank = Page::query()
            ->whereDoesntHave('sections')
            ->whereIn('status', ['published', 'scheduled'])
            ->count();

        return $blank > 0 ? (string) $blank : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /**
     * The admin resolves a page by its ULID, not by its path.
     *
     * `Page::getRouteKeyName()` returns `path`, so that a public URL is one
     * lookup — `/about/leadership` resolves straight to a page. That is right
     * for the site and impossible here: a path contains slashes, and a slash
     * cannot be a single route segment, so `/scghf-office/pages//about/leadership/edit`
     * is not a URL Laravel can match.
     *
     * The ULID is the identifier §1.1 already requires for anything appearing
     * in a URL, and it changes when nothing does — unlike the path, which moves
     * whenever a page is re-slugged or re-parented and would break every
     * bookmarked edit screen.
     */
    public static function getRecordRouteKeyName(): ?string
    {
        return 'ulid';
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPages::route('/'),
            'create' => CreatePage::route('/create'),
            'edit' => EditPage::route('/{record}/edit'),
        ];
    }
}
