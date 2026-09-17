<?php

declare(strict_types=1);

namespace App\Filament\Resources\Posts\Schemas;

use App\Enums\PageStatus;
use App\Filament\Support\MediaPicker;
use App\Filament\Support\SeoFields;
use App\Models\Post;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/**
 * Writing a post.
 *
 * ── The same lifecycle as a page, deliberately ──────────────────────────────
 *
 * `posts.status` is the same `PageStatus` enum, so "scheduled" means the same
 * thing in both places and an editor learns it once. A separate publishing
 * vocabulary for news would be a second set of rules to get wrong, on the
 * content type most likely to be scheduled in advance.
 *
 * ── The slug stops following the title once the post is out ─────────────────
 *
 * A published post has been linked to, shared and indexed. Renaming its address
 * because somebody fixed a typo in the headline turns every one of those links
 * into a 404 of the site's own making — so the slug follows the title only
 * while the post is still a draft.
 */
class PostForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make()->columnSpanFull()->tabs([

                Tabs\Tab::make(__('The post'))->schema([
                    TextInput::make('title')
                        ->label(__('Headline'))
                        ->required()
                        ->maxLength(191)
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (?string $state, callable $set, ?Post $record): void {
                            // See the note at the top: a published post keeps
                            // the address people have already linked to.
                            if ($record?->exists && $record->status !== PageStatus::Draft) {
                                return;
                            }

                            $set('slug', Str::slug((string) $state));
                        }),

                    TextInput::make('slug')
                        ->label(__('Address'))
                        ->required()
                        ->maxLength(191)
                        ->helperText(__('The last part of the web address. Changing it on a published post breaks every link to it — add a redirect if you must.')),

                    Textarea::make('excerpt')
                        ->label(__('Summary'))
                        ->rows(2)
                        ->maxLength(500)
                        /*
                         * Not optional in practice. This is what appears in the
                         * news listing, in a search result and in a link
                         * preview when somebody shares the post — and with it
                         * empty, all three fall back to the first sentence of
                         * the body, which is usually a date or a name.
                         */
                        ->helperText(__('Two lines. Shown in the news list, in search results, and when somebody shares the post.')),

                    RichEditor::make('body')
                        ->label(__('Body'))
                        ->columnSpanFull(),
                ]),

                Tabs\Tab::make(__('Publishing'))->schema([
                    Grid::make(2)->schema([
                        Select::make('status')
                            ->label(__('Status'))
                            ->options(PageStatus::options())
                            ->default(PageStatus::Draft->value)
                            ->required()
                            ->live(),

                        DateTimePicker::make('published_at')
                            ->label(__('Publish at'))
                            ->seconds(false)
                            ->helperText(__('Leave empty to publish immediately. A future date holds it until then.')),
                    ]),

                    Grid::make(2)->schema([
                        Select::make('blog_category_id')
                            ->label(__('Category'))
                            ->relationship('category', 'name')
                            ->searchable()
                            ->preload()
                            ->createOptionForm([
                                TextInput::make('name')->label(__('Name'))->required()->maxLength(191),
                                TextInput::make('slug')->label(__('Address'))->required()->maxLength(191),
                            ]),

                        /*
                         * The byline, and it is not "whoever is logged in".
                         *
                         * Somebody in the office frequently types up a piece
                         * written by the founder or a field officer, and a post
                         * bylined to the administrator account is wrong in a
                         * way readers can see. Defaulted to the current user
                         * because that is right most of the time, editable
                         * because it is not always.
                         */
                        Select::make('author_id')
                            ->label(__('By'))
                            ->relationship('author', 'name')
                            ->searchable()
                            ->preload()
                            ->default(fn (): ?int => auth()->id())
                            ->helperText(__('Whose name appears on the post — not necessarily whoever typed it in.')),
                    ]),

                    Select::make('tags')
                        ->label(__('Tags'))
                        ->relationship('tags', 'name')
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->createOptionForm([
                            TextInput::make('name')->label(__('Name'))->required()->maxLength(96),
                            TextInput::make('slug')->label(__('Address'))->required()->maxLength(96),
                        ])
                        ->helperText(__('How related posts are found. Two or three is plenty; a tag used once groups nothing.')),

                    Grid::make(2)->schema([
                        Toggle::make('is_featured')
                            ->label(__('Feature this post'))
                            ->helperText(__('Featured posts lead the news page and can be pulled into a page block.')),

                        Toggle::make('allow_comments')
                            ->label(__('Allow comments'))
                            ->default(true)
                            /*
                             * Honest about the flag. Comments are behind
                             * `FEATURE_BLOG_COMMENTS`, off by default, and a
                             * toggle that appears to enable something switched
                             * off site-wide is the kind of promise this project
                             * has a rule against.
                             */
                            ->helperText(__('Only has an effect while comments are switched on for the whole site. They are off at the moment.')),
                    ]),
                ]),

                Tabs\Tab::make(__('Image'))->schema([
                    MediaPicker::image('featured_image_id')
                        ->label(__('Featured image'))
                        ->helperText(__(
                            'Shown at the top of the post, in the news list, and when somebody shares it. '
                            .'Only images that are ready to publish appear here — one is missing if it has '
                            .'no alt text yet, or if its camera metadata has not been removed.'
                        )),
                ]),
                SeoFields::tab(),
            ]),
        ]);
    }
}
