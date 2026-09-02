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
            self::Json => json_decode($raw, true, 512, JSON_THROW_ON_ERROR),
            // Stored as minor units, so a setting round-trips through the same
            // exact-integer path as every other amount in the system.
            self::Money => Money::ofMinor((int) $raw),
            self::Media => (int) $raw,
            default => $raw,
        };
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
            self::Email => 'email:rfc,dns',
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
