<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Volunteer;
use App\Models\VolunteerShift;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VolunteerShift>
 */
class VolunteerShiftFactory extends Factory
{
    protected $model = VolunteerShift::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $starts = now()->addDay()->setTime(9, 0);

        return [
            'volunteer_id' => Volunteer::factory(),
            'starts_at' => $starts,
            'ends_at' => $starts->copy()->addHours(3),
            'activity' => 'Saturday feeding',
            'location' => 'Tamale office',
            'status' => VolunteerShift::STATUS_SCHEDULED,
        ];
    }
}
