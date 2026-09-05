<?php

declare(strict_types=1);

namespace App\Filament\Blocks;

use App\Blocks\BlockDefinition;
use App\Models\Media;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;

/**
 * Turns a `BlockDefinition` into the fields an editor fills in.
 *
 * ── One source for the form and the validation ──────────────────────────────
 *
 * The field list on a `BlockDefinition` already generates
 * `validationRules()`, and it now generates the form as well. Hand-writing the
 * admin form beside it would be two lists that agree until somebody changes
 * one — and the failure mode of that is a field an editor can fill in that the
 * validator then rejects, or worse, one it silently drops.
 *
 * So a block added to the registry appears in the panel with no admin work at
 * all, which is what makes twenty block types maintainable.
 */
class BlockFieldFactory
{
    /**
     * Every field for one block, ready to nest inside the sections repeater.
     *
     * @return array<int, Component>
     */
    public function fieldsFor(BlockDefinition $definition): array
    {
        $components = [];

        foreach ($definition->fields as $name => $spec) {
            $components[] = $this->field($name, $spec);
        }

        return $components;
    }

    /** @param array<string, mixed> $spec */
    private function field(string $name, array $spec): Component
    {
        $label = $this->label($name);
        $required = (bool) ($spec['required'] ?? false);

        $component = match ($spec['type'] ?? 'string') {
            'text' => Textarea::make("data.{$name}")->rows(3),

            /*
             * Prose, with a deliberately short toolbar.
             *
             * Headings start at h2 because the page's h1 is its title — a
             * second h1 inside the content breaks the document outline a
             * screen reader navigates by, and it is the commonest thing a
             * rich-text editor lets somebody do by accident.
             */
            'html' => RichEditor::make("data.{$name}")
                ->toolbarButtons(['bold', 'italic', 'link', 'bulletList', 'orderedList', 'h2', 'h3', 'blockquote', 'undo', 'redo']),

            'media' => $this->mediaPicker("data.{$name}"),

            'boolean' => Toggle::make("data.{$name}"),

            'integer' => TextInput::make("data.{$name}")->numeric(),

            'url' => TextInput::make("data.{$name}")->url()->maxLength(500),

            /*
             * A free list of short strings — preset amounts, ids. `TagsInput`
             * rather than a repeater because these are values, not records, and
             * a repeater for a list of numbers is four clicks per number.
             */
            'list' => TagsInput::make("data.{$name}")
                ->helperText(__('Press Enter after each one. Leave empty to use the site default.')),

            /*
             * A list of small records. The shape differs per block, so the
             * fields here are the ones every repeater block actually uses —
             * a definition needing something else declares its own field type
             * rather than this guessing.
             */
            'repeater' => Repeater::make("data.{$name}")
                ->schema([
                    TextInput::make('title')->label(__('Title'))->maxLength(160),
                    Textarea::make('body')->label(__('Text'))->rows(2)->maxLength(500),
                    TextInput::make('url')->label(__('Link'))->maxLength(500),
                ])
                ->itemLabel(fn (array $state): ?string => $state['title'] ?? null)
                ->reorderable()
                ->collapsible()
                ->defaultItems(0),

            default => TextInput::make("data.{$name}"),
        };

        $component = $component->label($label);

        if (isset($spec['max']) && method_exists($component, 'maxLength')) {
            $component = $component->maxLength((int) $spec['max']);
        }

        return $required && method_exists($component, 'required')
            ? $component->required()
            : $component;
    }

    /**
     * Choosing an image from the library.
     *
     * ── Only publishable files are offered ──────────────────────────────────
     *
     * `Media::isPublishable()` refuses anything without alt text or with its
     * camera metadata still on it. Offering a blocked file here would let an
     * editor place it on a page and then wonder why nothing rendered — the
     * image component refuses it too, silently, because rendering a broken
     * image on a donation page is worse than rendering none.
     *
     * So the gate is applied at the point of choosing, where there is room to
     * say why. The helper text is not decoration: "I uploaded it and it is not
     * in the list" is the exact question this causes, and it deserves an answer
     * on the same screen.
     */
    private function mediaPicker(string $name): Select
    {
        return Select::make($name)
            ->searchable()
            ->preload()
            ->options(fn (): array => Media::query()
                ->whereNotNull('metadata_stripped_at')
                ->whereNull('sanitisation_error')
                ->whereIn('mime_type', ['image/jpeg', 'image/png', 'image/webp', 'image/gif'])
                ->orderByDesc('id')
                ->limit(200)
                ->get()
                ->filter(fn (Media $media): bool => $media->isPublishable())
                ->mapWithKeys(fn (Media $media): array => [$media->getKey() => $media->name])
                ->all())
            ->helperText(__(
                'Only images that are ready to publish appear here. An image is missing if it has '
                .'no alt text yet, or if its camera metadata has not been removed — the media '
                .'library says which, for each one.'
            ));
    }

    /**
     * `primary_cta_label` → "Primary cta label".
     *
     * Filament's own humaniser would produce "Data.primary cta label", because
     * the field name carries the `data.` prefix that puts the value in the
     * right column.
     */
    private function label(string $name): string
    {
        return ucfirst(str_replace('_', ' ', $name));
    }
}
