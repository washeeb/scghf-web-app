<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The short list of symbols an editor can put on a card.
 *
 * Feature cards in the chosen template carry a small line icon each. Rather
 * than a free text field into which somebody types an icon set's internal
 * name, the block form offers this list, and `<x-ui.icon>` draws whichever
 * was chosen from the Heroicons set Filament already ships. Anything not on
 * the list is not drawn — a typo becomes no icon, never a broken one.
 */
final class Icons
{
    /** @var array<string, string> value => label */
    public const OPTIONS = [
        'heart' => 'Heart',
        'hand-raised' => 'Helping hand',
        'academic-cap' => 'Education',
        'book-open' => 'Open book',
        'beaker' => 'Health and science',
        'lifebuoy' => 'Relief',
        'home' => 'Home',
        'users' => 'People',
        'user-group' => 'Community',
        'face-smile' => 'Wellbeing',
        'shield-check' => 'Protection',
        'sun' => 'Hope',
        'sparkles' => 'Faith',
        'globe-alt' => 'Outreach',
        'building-library' => 'Institution',
        'gift' => 'Giving',
        'light-bulb' => 'Ideas',
        'chat-bubble-left-right' => 'Conversation',
        'star' => 'Star',
        'cake' => 'Celebration',
    ];

    /** @return array<string, string> */
    public static function options(): array
    {
        return array_map(fn (string $label): string => __($label), self::OPTIONS);
    }

    public static function exists(?string $name): bool
    {
        return $name !== null && array_key_exists($name, self::OPTIONS);
    }
}
