<?php

declare(strict_types=1);

namespace App\Filament\Resources\Comments\Tables;

use App\Models\Comment;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

/**
 * Moderating comments.
 *
 * ── Nothing appears without approval ────────────────────────────────────────
 *
 * An unmoderated comment form on a foundation's website is a spam target and a
 * safeguarding surface — a place where somebody can post a child's name, or an
 * accusation, under the foundation's own domain. So every comment arrives
 * pending, and this screen is the only way one becomes visible.
 *
 * ── Approve and spam, not edit ──────────────────────────────────────────────
 *
 * There is no way to change what somebody wrote. A moderator who edits a
 * comment and leaves it under its author's name has published words that person
 * did not write. The choices are approve it, mark it spam, or reject it.
 *
 * ── The count is on the comment's post, in the same breath ──────────────────
 *
 * `Post::comment_count` is denormalised so a news listing does not run a COUNT
 * per row. `Comment::approve()` and `markSpam()` already refresh it; the bulk
 * actions here go through those methods rather than a mass update, so the
 * number cannot drift away from the truth.
 */
class CommentsTable
{
    /** @return array<string, string> */
    public static function statuses(): array
    {
        return [
            Comment::STATUS_PENDING => __('Waiting'),
            Comment::STATUS_APPROVED => __('Approved'),
            Comment::STATUS_SPAM => __('Spam'),
            Comment::STATUS_REJECTED => __('Rejected'),
        ];
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at')
            ->columns([
                TextColumn::make('author_name')
                    ->label(__('From'))
                    ->searchable()
                    ->description(fn (Comment $record): string => (string) $record->author_email),

                TextColumn::make('body')
                    ->label(__('Comment'))
                    ->wrap()
                    ->limit(140)
                    ->searchable(),

                TextColumn::make('post.title')
                    ->label(__('On'))
                    ->limit(40)
                    ->toggleable(),

                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => static::statuses()[$state] ?? (string) $state)
                    ->color(fn (?string $state): string => match ($state) {
                        Comment::STATUS_APPROVED => 'success',
                        Comment::STATUS_PENDING => 'warning',
                        default => 'danger',
                    }),

                TextColumn::make('created_at')
                    ->label(__('Received'))
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options(static::statuses())
                    ->default(Comment::STATUS_PENDING),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label(__('Approve'))
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (Comment $record): bool => ! $record->isApproved())
                    ->action(fn (Comment $record) => $record->approve(static::moderator())),

                Action::make('spam')
                    ->label(__('Spam'))
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (Comment $record): bool => $record->status !== Comment::STATUS_SPAM)
                    ->action(fn (Comment $record) => $record->markSpam(static::moderator())),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('approve')
                        ->label(__('Approve'))
                        ->icon('heroicon-o-check')
                        ->color('success')
                        ->action(fn (Collection $records) => $records->each(
                            fn (Comment $record) => $record->approve(static::moderator())
                        ))
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('spam')
                        ->label(__('Mark spam'))
                        ->icon('heroicon-o-no-symbol')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->action(fn (Collection $records) => $records->each(
                            fn (Comment $record) => $record->markSpam(static::moderator())
                        ))
                        ->deselectRecordsAfterCompletion(),

                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    private static function moderator(): User
    {
        return auth()->user();
    }
}
