<?php

declare(strict_types=1);

namespace App\Support;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Stored HTML, made safe to print.
 *
 * ── "Sanitised on the way in" was a comment, not a fact ─────────────────────
 *
 * Every rich-text field on the public site was printed with `{!! !!}` on
 * the strength of a comment saying the editor sanitised it. Nothing did.
 * The editor is staff-only, which narrows the exposure to a compromised
 * staff account — and a compromised staff account is exactly the case the
 * rest of this phase is about. So it is sanitised on the way OUT, here, at
 * every one of those sites, with the allowlist below and nothing else.
 *
 * ── The allowlist ───────────────────────────────────────────────────────────
 *
 * What a rich-text editor produces: headings, paragraphs, emphasis, lists,
 * links, images from this site, tables, quotes, code. No scripts, no
 * event handlers, no `javascript:` URLs, no forms, no iframes, no styles.
 * Links open safely. Images are allowed only from this origin and data
 * URIs are refused, so a stored image cannot phone home.
 *
 * Symfony's sanitizer is already installed — Filament depends on it — so
 * this adds no dependency.
 */
final class Html
{
    private static ?HtmlSanitizer $sanitizer = null;

    public static function clean(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        return self::sanitizer()->sanitize($html);
    }

    private static function sanitizer(): HtmlSanitizer
    {
        if (self::$sanitizer !== null) {
            return self::$sanitizer;
        }

        $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);

        $config = (new HtmlSanitizerConfig)
            ->allowSafeElements()
            ->allowRelativeLinks()
            ->allowRelativeMedias()
            ->allowLinkSchemes(['https', 'http', 'mailto', 'tel'])
            ->allowMediaSchemes(['https', 'http'])
            ->allowMediaHosts(array_values(array_filter([$host])))
            ->forceHttpsUrls(app()->isProduction())
            ->allowAttribute('class', ['p', 'div', 'span', 'a', 'img', 'ul', 'ol', 'li', 'table', 'blockquote', 'figure', 'figcaption'])
            ->allowAttribute('id', ['h2', 'h3', 'h4'])
            ->allowAttribute('target', 'a')
            ->allowAttribute('loading', 'img')
            ->withMaxInputLength(2_000_000);

        return self::$sanitizer = new HtmlSanitizer($config);
    }

    /** For tests and after a config change. */
    public static function flush(): void
    {
        self::$sanitizer = null;
    }
}
