<?php

declare(strict_types=1);

namespace App\Filament\Resources\Media;

use App\Filament\Resources\Media\Pages\EditMedia;
use App\Filament\Resources\Media\Pages\ListMedia;
use App\Filament\Resources\Media\Schemas\MediaForm;
use App\Filament\Resources\Media\Tables\MediaTable;
use App\Models\Media;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * The media library, in the admin panel.
 *
 * ── There is no Create page, on purpose ─────────────────────────────────────
 *
 * You do not create a media row; you upload a file, and a row is what happens
 * next. A create form would let somebody produce a `media` record pointing at
 * nothing — and the only way in is `MediaLibrary::add()`, which is where the
 * MIME sniffing, the filename sanitising and the metadata stripping live.
 *
 * So uploading is an ACTION on the list page that hands files to that service,
 * and the edit page describes a file that already exists.
 *
 * ── What this screen is actually for ────────────────────────────────────────
 *
 * Not browsing. The job that brings somebody here is "why will this image not
 * go on the page", and the answer is always one of two things: it has no alt
 * text, or its metadata has not been stripped. Both are surfaced as the first
 * column and as the default filter, because a library that makes you click into
 * forty files to find the one that is blocked is a library people work around.
 */
class MediaResource extends Resource
{
    protected static ?string $model = Media::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static string|UnitEnum|null $navigationGroup = 'Library';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationLabel(): string
    {
        return __('Media library');
    }

    public static function getNavigationBadge(): ?string
    {
        /*
         * The count of files that cannot be published.
         *
         * A badge with a number on it is the only part of this screen somebody
         * sees without opening it, so it holds the thing that needs a person:
         * files blocked from use. Zero shows nothing rather than "0", because a
         * permanent badge is one people stop reading.
         */
        $blocked = static::blockedQuery()->count();

        return $blocked > 0 ? (string) $blocked : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return static::blockedQuery()->count() > 0 ? 'warning' : null;
    }

    /**
     * Files that cannot go on a page.
     *
     * Missing alt text OR unsanitised. Expressed here rather than in three
     * places so the badge, the filter and the empty state cannot disagree about
     * what "blocked" means.
     *
     * @return Builder<Media>
     */
    public static function blockedQuery(): Builder
    {
        return Media::query()
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('metadata_stripped_at')
                    ->orWhereNotNull('sanitisation_error')
                    ->orWhere(function (Builder $inner): void {
                        // A decorative image legitimately has no alt text, and
                        // `custom_properties` is JSON — so the check has to
                        // reach inside it rather than testing the column alone.
                        $inner->whereNull('alt_text')
                            ->whereRaw("JSON_EXTRACT(custom_properties, '$.decorative') IS NULL");
                    });
            });
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
        return ['name', 'file_name', 'alt_text'];
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
        return ['File' => $record->file_name];
    }

    public static function form(Schema $schema): Schema
    {
        return MediaForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MediaTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMedia::route('/'),
            'edit' => EditMedia::route('/{record}/edit'),
        ];
    }
}
