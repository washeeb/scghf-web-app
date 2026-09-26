<?php

declare(strict_types=1);

namespace App\Blocks;

/**
 * One block type an editor can place on a page.
 *
 * Immutable, defined in PHP, never in the database — see the block_types
 * migration for why. The Blade view and the field list ship together, so they
 * cannot drift apart across a deploy.
 */
final readonly class BlockDefinition
{
    /**
     * @param  string  $key  stored in page_sections.block_type
     * @param  array<string, array<string, mixed>>  $fields  field name => spec
     * @param  int|null  $maxPerPage  null = unlimited
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $description,
        public string $category,
        public string $icon,
        public array $fields = [],
        public ?int $maxPerPage = null,
        public bool $isEnabledByDefault = true,
    ) {}

    /** The Blade component that renders it: `blocks.hero`. */
    public function view(): string
    {
        return 'blocks.'.$this->key;
    }

    /**
     * Laravel validation rules for this block's `data` payload.
     *
     * @return array<string, string>
     */
    public function validationRules(): array
    {
        $rules = [];

        foreach ($this->fields as $name => $spec) {
            $parts = [];
            $parts[] = ($spec['required'] ?? false) ? 'required' : 'nullable';

            $parts[] = match ($spec['type'] ?? 'string') {
                'integer', 'media' => 'integer',
                'boolean' => 'boolean',
                'repeater', 'list', 'slides' => 'array',
                'url' => 'url',
                'colour' => 'string',
                default => 'string',
            };

            if (isset($spec['max'])) {
                $parts[] = 'max:'.$spec['max'];
            }

            $rules['data.'.$name] = implode('|', $parts);
        }

        return $rules;
    }

    /**
     * Defaults for a newly placed block, so a fresh block renders rather than
     * throwing on a missing key.
     *
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return collect($this->fields)
            ->map(fn (array $spec): mixed => $spec['default'] ?? match ($spec['type'] ?? 'string') {
                'repeater', 'list', 'slides' => [],
                'boolean' => false,
                'integer' => null,
                default => null,
            })
            ->all();
    }
}
