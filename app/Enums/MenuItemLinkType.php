<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a menu item points at.
 *
 * Kept explicit rather than "just store a URL" because a menu item pointing at
 * a CMS page must follow that page when its slug changes. A hardcoded URL in a
 * menu is how a site accumulates broken navigation nobody notices.
 */
enum MenuItemLinkType: string
{
    /** A CMS page. Resolves through the relation, so a slug change follows. */
    case Page = 'page';

    /** A named route — /donate, /shop, and the other coded pages. */
    case Route = 'route';

    /** A project, cause, product or post. Polymorphic. */
    case Entity = 'entity';

    /** An external address. The only kind that opens in a new tab by default. */
    case External = 'external';

    /** A parent with children and no destination of its own. */
    case Heading = 'heading';

    public function label(): string
    {
        return match ($this) {
            self::Page => 'A page on this site',
            self::Route => 'A built-in section',
            self::Entity => 'A project, cause or product',
            self::External => 'An external link',
            self::Heading => 'A heading (no link)',
        };
    }

    public function opensInNewTabByDefault(): bool
    {
        return $this === self::External;
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
