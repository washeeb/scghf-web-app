{{--
    The site settings screen.

    Everything is declared in `App\Filament\Pages\ManageSettings::content()` —
    the tabs, the fields and the save button all come from the schema, so this
    file has nothing to say beyond where to put it.
--}}
<x-filament-panels::page>
    {{ $this->content }}
</x-filament-panels::page>
