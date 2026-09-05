<?php

declare(strict_types=1);

namespace App\Enums;

use App\ValueObjects\Money;

/**
 * How a setting's stored string is interpreted.
 *
 * Everything is persisted as text — one column, one shape, no sparse typed
 * columns — and cast on the way out. The type also drives which Filament field
 * renders it and which validation rule applies, so a phone number is not editable
 * as free text and a colour is not editable as a number.
 */
enum SettingType: string
{
    case String = 'string';
    case Text = 'text';
    case Html = 'html';
    case Integer = 'integer';
    case Boolean = 'boolean';
    case Json = 'json';
    case Money = 'money';
    case Email = 'email';
    case Url = 'url';
    case Phone = 'phone';
    case Colour = 'colour';
    case Select = 'select';
    case Media = 'media';

    /** Turn the stored string into the value the application works with. */
    public function cast(?string $raw): mixed
    {
        if ($raw === null) {
            return null;
        }

        return match ($this) {
            self::Integer => (int) $raw,
            self::Boolean => filter_var($raw, FILTER_VALIDATE_BOOL),
            self::Json => self::decodeJson($raw),
            // Stored as minor units, so a setting round-trips through the same
            // exact-integer path as every other amount in the system.
            self::Money => Money::ofMinor((int) $raw),
            self::Media => (int) $raw,
            default => $raw,
        };
    }

    /**
     * Decode a JSON setting without taking the page down.
     *
     * ── Why this does not throw ─────────────────────────────────────────────
     *
     * `cast()` runs on every setting the moment the repository loads, which is
     * once per request on every page. `JSON_THROW_ON_ERROR` here meant that a
     * single unparseable value anywhere in the table was a 500 on the entire
     * site — including the donation page, since `donations.presets` is JSON.
     *
     * It was found the honest way: an unfilled `{{PLACEHOLDER}}`, which every
     * other type treats as absent, is not valid JSON. `Settings::get()` has the
     * check that turns a placeholder into "not set", and it never got the
     * chance to run because the cast threw first.
     *
     * Null is the right failure. `get()` returns the caller's default for it,
     * so a malformed list behaves exactly like an empty one — and the value is
     * still raw in the column, so `Settings::unfilled()` and the preflight
     * command still report it to somebody who can fix it.
     */
    private static function decodeJson(string $raw): mixed
    {
        try {
            return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }

    /** Turn an application value back into the stored string. */
    public function serialise(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($this) {
            self::Boolean => $value ? '1' : '0',
            self::Json => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            self::Money => (string) ($value instanceof Money ? $value->minor : (int) $value),
            default => (string) $value,
        };
    }

    /** Laravel validation applied in the admin form. */
    public function validationRule(): string
    {
        return match ($this) {
            self::Integer, self::Media => 'integer',
            self::Boolean => 'boolean',
            self::Json => 'json',
            self::Money => 'integer|min:0',
            /*
             * `rfc` and not `dns`.
             *
             * A DNS lookup inside a form submission makes saving the settings
             * screen depend on the web server's resolver, and shared hosting is
             * exactly where that goes wrong — a slow resolver turns "save the
             * office address" into a request that times out, with no
             * explanation an editor could act on. Whether the domain actually
             * receives mail is a deliverability question, and it belongs in
             * `scghf:preflight` where a failure is a report rather than a
             * blocked save.
             */
            self::Email => 'email:rfc',
            self::Url => 'url',
            // Ghanaian mobile in any of the shapes donors actually type.
            self::Phone => 'regex:/^(\+?233|0)[2345][0-9]{8}$/',
            self::Colour => 'regex:/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/',
            self::Text, self::Html => 'string',
            default => 'string|max:1000',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::String => 'Short text',
            self::Text => 'Long text',
            self::Html => 'Rich text',
            self::Integer => 'Number',
            self::Boolean => 'Yes / No',
            self::Json => 'Structured data',
            self::Money => 'Amount (GH₵)',
            self::Email => 'Email address',
            self::Url => 'Web address',
            self::Phone => 'Phone number',
            self::Colour => 'Colour',
            self::Select => 'Choice',
            self::Media => 'Image or file',
        };
    }
}
