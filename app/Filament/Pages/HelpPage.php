<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Support\Html;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * The admin manual, inside the admin.
 *
 * The chapters are Markdown in `resources/manual/` — the same files a
 * developer reads on GitHub — rendered here so a member of staff never
 * needs a repository to find out how to record a cash gift. Every signed-in
 * staff member may read it: the manual says who may do what; it does not
 * let anybody do it.
 *
 * `/help` opens the index; `/help/05-donations` a chapter. Images live in
 * `resources/manual/images` and are served through `manual.image`, behind
 * the panel's authentication.
 */
class HelpPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 99;

    protected string $view = 'filament.pages.help';

    protected static ?string $slug = 'help';

    public ?string $chapter = null;

    public static function getNavigationLabel(): string
    {
        return __('Help & manual');
    }

    /** Every member of staff who can open the panel may read the manual. */
    public static function canAccess(): bool
    {
        return auth()->user()?->canAccessPanel() ?? false;
    }

    public function getTitle(): string
    {
        return $this->chapter === null ? __('The admin manual') : $this->chapterTitle($this->chapter);
    }

    public static function getRoutePath(Panel $panel): string
    {
        return '/help/{chapter?}';
    }

    public function mount(?string $chapter = null): void
    {
        $this->chapter = $chapter !== null && self::chapterExists($chapter) ? $chapter : null;
    }

    /** @return array<int, array{slug: string, title: string, url: string}> */
    public function chapters(): array
    {
        $chapters = [];

        foreach (glob(self::directory().'/*.md') ?: [] as $file) {
            $slug = basename($file, '.md');

            if ($slug === 'README') {
                continue;
            }

            $chapters[] = ['slug' => $slug, 'title' => $this->chapterTitle($slug), 'url' => static::getUrl(['chapter' => $slug])];
        }

        return $chapters;
    }

    public function html(): HtmlString
    {
        $file = self::directory().'/'.($this->chapter ?? 'README').'.md';
        $markdown = (string) file_get_contents($file);

        // Images and chapter links are written for GitHub; point them here.
        $markdown = preg_replace_callback('/\]\(images\/([a-z0-9._-]+)\)/i', fn (array $m): string => ']('.route('manual.image', ['file' => $m[1]]).')', $markdown) ?? $markdown;
        $markdown = preg_replace_callback('/\]\(([0-9]{2}-[a-z0-9-]+)\.md\)/i', fn (array $m): string => ']('.static::getUrl(['chapter' => $m[1]]).')', $markdown) ?? $markdown;
        $markdown = str_replace('](README.md)', ']('.static::getUrl().')', $markdown);

        // The manual is ours; nothing in it is user input. Rendered with
        // HTML allowed so the tables and images come through, then passed
        // through the same sanitiser as CMS content anyway.
        $html = Str::markdown($markdown, ['html_input' => 'allow', 'allow_unsafe_links' => false]);

        return new HtmlString(Html::clean($html));
    }

    public static function directory(): string
    {
        return resource_path('manual');
    }

    public static function chapterExists(string $slug): bool
    {
        return preg_match('/^[0-9]{2}-[a-z0-9-]+$/', $slug) === 1 && is_file(self::directory().'/'.$slug.'.md');
    }

    private function chapterTitle(string $slug): string
    {
        $lines = file(self::directory().'/'.$slug.'.md', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $first = (string) ($lines[0] ?? '');

        return trim(ltrim(trim($first), '#')) ?: Str::headline($slug);
    }
}
