<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Office;
use Illuminate\Database\Seeder;

/**
 * The first office, from the contact settings, once.
 *
 * Only when the table is empty: an office somebody has edited is theirs.
 * Placeholders (`{{OFFICE_ADDRESS}}`) are carried across as they are — the
 * settings screen already flags them, and a blank would hide the gap.
 */
class OfficeSeeder extends Seeder
{
    public function run(): void
    {
        if (Office::query()->exists()) {
            return;
        }

        Office::create([
            'name' => (string) setting('general.short_name', 'Head office'),
            'address' => setting('contact.address'),
            'gps_address' => setting('contact.gps_address'),
            'city' => setting('contact.city'),
            'region' => setting('contact.region'),
            'phone' => setting('contact.phone_primary'),
            'whatsapp' => setting('contact.whatsapp'),
            'email' => setting('contact.email_general'),
            'hours' => ['mon' => $h = (string) setting('contact.office_hours', ''), 'tue' => $h, 'wed' => $h, 'thu' => $h, 'fri' => $h],
            'is_primary' => true,
            'is_active' => true,
        ]);
    }
}
