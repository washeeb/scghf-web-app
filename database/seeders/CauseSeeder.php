<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\CauseStatus;
use App\Models\Cause;
use App\Models\Division;
use Illuminate\Database\Seeder;

/**
 * The General Fund, and one open appeal per division.
 *
 * **The General Fund is the point of this seeder.** `donations.cause_id` is NOT
 * NULL in Module 4, so a gift made through a generic donate form with no appeal
 * chosen still needs somewhere to go. Without this row that donation either
 * fails at the last step — after the donor has paid — or gets attached to
 * whichever appeal happens to be first, which is worse.
 *
 * It is seeded LOCKED and refuses deletion.
 *
 * The per-division appeals are seeded as DRAFT with no target. They are
 * placeholders for the foundation to write, not content invented on its behalf,
 * and nothing is public until somebody writes it.
 */
class CauseSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedGeneralFund();

        foreach (Division::query()->orderBy('sort_order')->get() as $division) {
            $this->seedDivisionFund($division);
        }
    }

    private function seedGeneralFund(): void
    {
        $fund = Cause::withTrashed()->firstOrNew(['slug' => 'general-fund']);

        $fund->forceFill([
            'title' => 'General Fund',
            'is_general_fund' => true,
            'is_locked' => true,
            'status' => CauseStatus::Active,
            'is_published' => true,
            'allow_recurring' => true,
            'allow_fee_cover' => true,
            // No target. A goal of zero would render as a progress bar
            // permanently at 100%, and the General Fund is never "complete".
            'goal_minor' => null,
            'sort_order' => 0,
            'deleted_at' => null,
        ]);

        if (! $fund->exists) {
            $fund->summary = 'Gifts to the General Fund are directed where the need is greatest '
                .'across the foundation\'s work.';
            $fund->published_at = now();
        }

        $fund->save();
    }

    private function seedDivisionFund(Division $division): void
    {
        $slug = $division->slug.'-fund';

        $cause = Cause::withTrashed()->firstOrNew(['slug' => $slug]);

        $cause->forceFill([
            'division_id' => $division->id,
            'title' => $division->name.' Fund',
            'sort_order' => $division->sort_order + 1,
            'deleted_at' => null,
        ]);

        if (! $cause->exists) {
            /*
             * Draft, unpublished, and with no summary written for it. The
             * foundation writes its own appeal copy — seeding persuasive prose
             * on its behalf would put words in its mouth, and this text goes in
             * front of donors.
             */
            $cause->status = CauseStatus::Draft;
            $cause->is_published = false;
            $cause->is_tax_deductible = false;
        }

        $cause->save();
    }
}
