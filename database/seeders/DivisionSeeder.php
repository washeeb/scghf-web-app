<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Division;
use App\Models\FocusArea;
use Illuminate\Database\Seeder;

/**
 * The four divisions, and the focus areas within each.
 *
 * Taken from the foundation profile via Blueprint §1 — not invented. Each
 * division's `colour_token` names a token that ThemeSettingsSeeder already
 * defines and that the contrast test already checks in both light and dark.
 * Storing a token rather than a hex keeps colour out of the data layer, which
 * is the same rule that keeps it out of Blade.
 *
 * Idempotent, and it never overwrites prose the foundation has since edited:
 * only structural fields are refreshed on re-run. Divisions are seeded LOCKED,
 * because projects, causes, donations and years of reporting hang off them.
 */
class DivisionSeeder extends Seeder
{
    /**
     * @var array<int, array{
     *     slug: string, name: string, tagline: string, colour_token: string,
     *     icon: string, summary: string, focus_areas: array<int, string>
     * }>
     */
    private const DIVISIONS = [
        [
            'slug' => 'life-spring',
            'name' => 'Life Spring Foundation',
            'tagline' => 'Health',
            'colour_token' => 'division-lifespring',
            'icon' => 'heroicon-o-heart',
            'summary' => 'Basic health outreach, preventive health education and support for '
                .'vulnerable patients in communities with limited access to healthcare.',
            'focus_areas' => [
                'Basic health outreach',
                'Preventive health education',
                'Community health screening',
                'Support for vulnerable patients',
                'Maternal and child health awareness',
                'Wellness campaigns',
                'Health-worker partnerships',
            ],
        ],
        [
            'slug' => 'brightpath',
            'name' => 'BrightPath Fund Initiative',
            'tagline' => 'Education',
            'colour_token' => 'division-brightpath',
            'icon' => 'heroicon-o-academic-cap',
            'summary' => 'Scholarships, learning materials and mentorship for needy but '
                .'promising students, orphans and vulnerable children.',
            'focus_areas' => [
                'Scholarships and bursaries',
                'School supplies and learning materials',
                'Mentorship',
                'Career guidance',
                'At-risk-of-dropout support',
                'Character development',
                'Skills and leadership training',
            ],
        ],
        [
            'slug' => 'legacy-of-love',
            'name' => 'Legacy of Love Initiative',
            'tagline' => 'Orphans, Widows & Widowers',
            'colour_token' => 'division-legacy',
            'icon' => 'heroicon-o-users',
            'summary' => 'Practical, emotional and spiritual support for orphans, widows, '
                .'widowers and families after loss.',
            'focus_areas' => [
                'Orphans and vulnerable children',
                'Widows and widowers',
                'Bereaved families',
                'Food and clothing',
                'Household support',
                'Emotional and spiritual encouragement',
                'Livelihood and skills training',
                'Seasonal donations and community visits',
            ],
        ],
        [
            'slug' => 'every-soul-missions',
            'name' => 'Every Soul Missions',
            'tagline' => 'Evangelism',
            'colour_token' => 'division-everysoul',
            'icon' => 'heroicon-o-book-open',
            'summary' => 'Community evangelism, discipleship, prayer and counselling, and '
                .'outreach to vulnerable groups.',
            'focus_areas' => [
                'Community evangelism',
                'Prayer and counselling',
                'Bible distribution',
                'Christian literature',
                'Discipleship',
                'Hospital and home visits',
                'Outreach to vulnerable groups',
                'Church and mission partnerships',
            ],
        ],
    ];

    public function run(): void
    {
        foreach (self::DIVISIONS as $order => $definition) {
            $division = Division::withTrashed()->firstOrNew(['slug' => $definition['slug']]);

            /*
             * Structural fields are refreshed on every run so a correction here
             * reaches production. Prose the foundation may have edited —
             * `summary` and `description` — is written only when creating,
             * which is the same contract SettingsSeeder honours.
             */
            $division->forceFill([
                'name' => $definition['name'],
                'tagline' => $definition['tagline'],
                'colour_token' => $definition['colour_token'],
                'icon' => $definition['icon'],
                'sort_order' => $order,
                'is_locked' => true,
                'deleted_at' => null,
            ]);

            if (! $division->exists) {
                $division->summary = $definition['summary'];
                $division->is_active = true;
            }

            $division->save();

            $this->seedFocusAreas($division, $definition['focus_areas']);
        }
    }

    /** @param array<int, string> $names */
    private function seedFocusAreas(Division $division, array $names): void
    {
        foreach ($names as $order => $name) {
            /*
             * Slugs are global, so they are prefixed with the division. Two
             * divisions could both reasonably run "mentorship", and a focus
             * area appears in a URL — an unprefixed collision would be a
             * routing ambiguity, not just a database error.
             */
            $slug = $division->slug.'-'.str($name)->slug();

            $area = FocusArea::withTrashed()->firstOrNew(['slug' => $slug]);

            $area->forceFill([
                'division_id' => $division->id,
                'name' => $name,
                'sort_order' => $order,
                'deleted_at' => null,
            ]);

            if (! $area->exists) {
                $area->is_active = true;
            }

            $area->save();
        }
    }
}
