<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Blocks\BlockDefinition;
use App\Blocks\BlockRegistry;
use App\Models\BlockType;
use Illuminate\Database\Seeder;

/**
 * Projects the PHP block registry into the database.
 *
 * A block added to the registry appears in the admin picker on the next deploy
 * with no migration. A block REMOVED from the registry is disabled rather than
 * deleted, so pages that already have one placed keep their row — the section
 * simply stops rendering, which is recoverable, whereas deleting the row would
 * silently destroy content.
 */
class BlockTypeSeeder extends Seeder
{
    public function run(): void
    {
        $registry = app(BlockRegistry::class);
        $order = 0;

        foreach ($registry->all() as $block) {
            /** @var BlockDefinition $block */
            $existing = BlockType::where('key', $block->key)->first();

            BlockType::updateOrCreate(
                ['key' => $block->key],
                [
                    // Availability is an admin decision, so an existing choice
                    // is preserved; only new rows take the default.
                    'is_enabled' => $existing?->is_enabled ?? $block->isEnabledByDefault,
                    'sort_order' => $order++,
                    'max_per_page' => $block->maxPerPage,
                ],
            );
        }

        $orphaned = BlockType::whereNotIn('key', $registry->keys())
            ->where('is_enabled', true)
            ->get();

        foreach ($orphaned as $row) {
            $row->update(['is_enabled' => false]);
        }

        $this->command?->info(sprintf(
            'Block types: %d registered%s.',
            $registry->all()->count(),
            $orphaned->isNotEmpty() ? sprintf(', %d disabled (no longer in the registry)', $orphaned->count()) : '',
        ));
    }
}
