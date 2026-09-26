<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ShippingZone;
use Illuminate\Database\Seeder;

/**
 * Delivery zones covering all sixteen Ghanaian regions, plus collection.
 *
 * Seeded with NO RATES and INACTIVE. The zones are structural — the regions of
 * Ghana are a fact, and a customer in Savannah should not find their region
 * missing from a dropdown — but what delivery costs is a commercial decision
 * the foundation makes with a courier, and inventing a price here would put a
 * number in front of a customer that nobody agreed to honour.
 *
 * The grouping follows delivery reality rather than administrative tidiness:
 * Greater Accra is its own zone because most orders go there and it is cheapest
 * to serve; the north is grouped because a courier prices it as one trip.
 */
class ShippingZoneSeeder extends Seeder
{
    /** @var array<int, array{slug: string, name: string, regions: array<int, string>, pickup?: bool}> */
    private const ZONES = [
        [
            'slug' => 'collection',
            'name' => 'Collection from the foundation',
            'regions' => [],
            'pickup' => true,
        ],
        [
            'slug' => 'greater-accra',
            'name' => 'Greater Accra',
            'regions' => ['Greater Accra'],
        ],
        [
            'slug' => 'southern-ghana',
            'name' => 'Southern Ghana',
            'regions' => ['Ashanti', 'Central', 'Eastern', 'Volta', 'Western', 'Western North'],
        ],
        [
            'slug' => 'middle-belt',
            'name' => 'Middle belt',
            'regions' => ['Ahafo', 'Bono', 'Bono East', 'Oti'],
        ],
        [
            'slug' => 'northern-ghana',
            'name' => 'Northern Ghana',
            // Grouped because a courier prices the north as one trip, not
            // because the regions are otherwise alike.
            'regions' => ['Northern', 'North East', 'Savannah', 'Upper East', 'Upper West'],
        ],
    ];

    public function run(): void
    {
        foreach (self::ZONES as $order => $definition) {
            $zone = ShippingZone::firstOrNew(['slug' => $definition['slug']]);

            $zone->forceFill([
                'name' => $definition['name'],
                'regions' => $definition['regions'],
                'is_pickup' => $definition['pickup'] ?? false,
                'sort_order' => $order,
            ]);

            if (! $zone->exists) {
                /*
                 * Inactive until the foundation adds a rate. An active zone with
                 * no rate would let a customer reach checkout and be told the
                 * shop cannot work out delivery — which is a worse experience
                 * than the region simply not being offered yet.
                 */
                $zone->is_active = false;
            }

            $zone->save();
        }

        $covered = collect(self::ZONES)->flatMap(fn (array $z): array => $z['regions'])->unique();

        $this->command?->info(sprintf(
            'Shipping: %d zones seeded, covering %d of Ghana\'s %d regions. '
            .'All inactive until rates are set.',
            count(self::ZONES),
            $covered->count(),
            count(ShippingZone::REGIONS),
        ));
    }
}
