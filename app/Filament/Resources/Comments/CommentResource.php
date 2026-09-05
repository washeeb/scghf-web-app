<?php

declare(strict_types=1);

namespace App\Filament\Resources\Comments;

use App\Filament\Resources\Comments\Pages\ListComments;
use App\Filament\Resources\Comments\Tables\CommentsTable;
use App\Models\Comment;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class CommentResource extends Resource
{
    protected static ?string $model = Comment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|UnitEnum|null $navigationGroup = 'Inbox';

    protected static ?int $navigationSort = 20;

    protected static ?string $modelLabel = 'Comment';

    protected static ?string $pluralModelLabel = 'Comments';

    protected static ?string $recordTitleAttribute = 'author_name';

    public static function table(Table $table): Table
    {
        return CommentsTable::configure($table);
    }

    /**
     * How many are waiting.
     *
     * A comment sitting unmoderated is invisible on the site, so nobody
     * complains — which is exactly why it needs a number in the sidebar.
     */
    public static function getNavigationBadge(): ?string
    {
        $pending = Comment::query()->where('status', Comment::STATUS_PENDING)->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /**
     * Hidden while comments are switched off site-wide.
     *
     * `FEATURE_BLOG_COMMENTS` is off by default and there is no public comment
     * form yet. A moderation queue in the sidebar that can only ever say zero
     * is the "on and empty" shape this project has a rule against — so the
     * screen exists, works, and simply is not shown until there is something
     * for it to moderate.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return (bool) config('features.blog_comments', false);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListComments::route('/'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
