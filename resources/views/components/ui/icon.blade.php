{{--
    A line icon from the Heroicons outline set Filament already ships.

    Decorative always: the text beside it carries the meaning, so the SVG is
    hidden from assistive technology rather than announced as "heart".

    Editors choose from the short list in App\Support\Icons (the card
    forms); templates may name any icon in the set. A name the set does not
    have draws nothing — never a broken image, never an exception on a
    public page.

    @param name   a Heroicons outline name, e.g. `heart`, `moon`, `user-circle`
--}}
@props(['name' => null])

@php
    $svg = null;

    if (is_string($name) && preg_match('/^[a-z0-9-]+$/', $name)) {
        try {
            $svg = svg('heroicon-o-'.$name, $attributes->get('class', 'size-6'), ['aria-hidden' => 'true', 'focusable' => 'false']);
        } catch (BladeUI\Icons\Exceptions\SvgNotFound) {
            $svg = null;
        }
    }
@endphp

@if ($svg !== null)
    {{ $svg }}
@endif
