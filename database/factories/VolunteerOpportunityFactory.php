<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\VolunteerOpportunity;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<VolunteerOpportunity>
 */
class VolunteerOpportunityFactory extends Factory
{
    protected $model = VolunteerOpportunity::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $title = Str::title(fake()->unique()->words(3, true)).' Volunteer';

        return [
            'title' => $title,
            'slug' => Str::slug($title),
            'summary' => 'Supporting the foundation in the field.',
            // True by default, matching the model — the safe assumption for a
            // foundation whose work is orphans and widows.
            'involves_vulnerable_contact' => true,
            'placement_type' => VolunteerOpportunity::PLACEMENT_FIELD,
            'region' => 'Greater Accra',
            'is_published' => true,
            'published_at' => now()->subWeek(),
        ];
    }

    /** A role with no unsupervised contact — folding leaflets, stewarding. */
    public function withoutVulnerableContact(): static
    {
        return $this->state(fn (): array => [
            'involves_vulnerable_contact' => false,
            'placement_type' => VolunteerOpportunity::PLACEMENT_OFFICE,
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn (): array => ['closes_on' => now()->subDay()->toDateString()]);
    }
}
