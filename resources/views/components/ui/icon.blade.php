{{--
    A line icon from the short list in App\Support\Icons.

    Decorative always: the text beside it carries the meaning, so the SVG is
    hidden from assistive technology rather than announced as "heart".
    Anything not on the list draws nothing.

    @param name   one of Icons::OPTIONS
--}}
@props(['name' => null])

@if (App\Support\Icons::exists($name))
    {{ svg('heroicon-o-'.$name, $attributes->get('class', 'size-6'), ['aria-hidden' => 'true', 'focusable' => 'false']) }}
@endif
