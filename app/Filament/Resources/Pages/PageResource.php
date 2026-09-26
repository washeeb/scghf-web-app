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
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

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

    protected static string|UnitEnum|null $navigationGroup = 'Website';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'title';

    public static function getNavigationLabel(): string
    {
        return __('Pages');
    }

    /**
     * What the global search looks inside.
     *
     * A search that only matches titles is one people stop using: the thing
     * somebody remembers about a page is rarely its heading. `path` and the
     * body-ish field are here for that reason.
     *
     * Results are still policy-checked — Filament resolves each through the
     * resource's own query — so searching does not become a way to read a
     * record somebody may not open.
     *
     * @return array<int, string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['title', 'path', 'summary'];
    }

    /**
     * The line under a search result.
     *
     * Two records with the same title are ordinary — "Our Story" as a page and
     * as a news post — and a result list that cannot tell them apart sends
     * somebody into the wrong one.
     *
     * @return array<string, string|null>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return ['Address' => $record->path, 'Status' => $record->status->label()];
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
