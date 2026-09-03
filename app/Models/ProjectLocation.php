<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Anonymiser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where a project's work happens.
 *
 * **This is site data, not person data.** A community named here is the
 * community a borehole was dug in, published on purpose.
 *
 * Beneficiary location is an entirely different thing: it is personal data,
 * classified `community` and `geolocation` in the privacy policy, and destroyed
 * at retention expiry. Nothing about a beneficiary points at this table, and
 * nothing here should ever be populated from a beneficiary record.
 *
 * @see Anonymiser
 */
class ProjectLocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id', 'name', 'region', 'district', 'community',
        'latitude', 'longitude', 'is_primary', 'sort_order',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_primary' => false,
        'sort_order' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            // Cast as strings, not floats. A float latitude drifts under
            // arithmetic, and a drifting map pin is a wrong map pin.
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'is_primary' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // One primary per project. Promoting a location demotes the previous
        // one rather than leaving two, which would make "the location" of a
        // project depend on row order.
        static::saved(function (self $location): void {
            if (! $location->is_primary) {
                return;
            }

            static::query()
                ->where('project_id', $location->project_id)
                ->whereKeyNot($location->getKey())
                ->where('is_primary', true)
                ->update(['is_primary' => false]);
        });
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function hasCoordinates(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /** A one-line label: "Asokwa, Kumasi Metropolitan, Ashanti". */
    public function fullLabel(): string
    {
        return collect([$this->community, $this->district, $this->region])
            ->filter()
            ->unique()
            ->implode(', ');
    }
}
