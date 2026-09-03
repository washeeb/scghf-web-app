<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ProductCategory;
use App\Shop\RegulatoryScreener;
use Illuminate\Database\Seeder;

/**
 * The shop taxonomy the trustees agreed.
 *
 * Seeded from `config('compliance.shop.approved_categories')` rather than
 * duplicated here, so the policy and the data cannot drift: adding a category
 * to the policy adds it to the shop on the next deploy, and nothing appears in
 * the shop that is not in the policy.
 *
 * Categories are seeded INACTIVE where they have no products yet — an empty
 * category in the navigation is a dead end for a visitor.
 */
class ShopCategorySeeder extends Seeder
{
    public function run(): void
    {
        $screener = app(RegulatoryScreener::class);

        foreach ($screener->approvedCategories() as $key => $definition) {
            $category = ProductCategory::withTrashed()->firstOrNew(['policy_key' => $key]);

            $category->forceFill([
                'name' => $definition['label'],
                'slug' => $key,
                'policy_key' => $key,
                'sort_order' => array_search($key, array_keys($screener->approvedCategories()), true),
                'deleted_at' => null,
            ]);

            if (! $category->exists) {
                /*
                 * The policy's example items become the description, so an
                 * editor adding a product can see what the category was agreed
                 * to cover without opening the compliance config.
                 */
                $category->description = implode(' · ', $definition['items']);
                $category->is_active = false;
            }

            $category->save();
        }
    }
}
