<?php

declare(strict_types=1);

namespace App\Communications;

use App\ValueObjects\Money;
use Illuminate\Support\HtmlString;

/**
 * Plausible values for a template preview.
 *
 * A preview with `{{donor_name}}` still in it tells the editor nothing about
 * how the sentence reads. These are the values a real message would carry,
 * chosen to be obviously Ghanaian and obviously fake. A name nobody has
 * listed here falls back to its own name in brackets, which is the honest
 * thing to show for a variable the software knows only by name.
 */
final class SampleVariables
{
    /** @return array<string, mixed> */
    public static function for(array $names): array
    {
        $values = [];

        foreach ($names as $name) {
            $values[$name] = self::value($name);
        }

        return $values;
    }

    public static function value(string $name): mixed
    {
        $known = [
            'donor_name' => 'Ama Mensah',
            'customer_name' => 'Ama Mensah',
            'name' => 'Ama Mensah',
            'first_name' => 'Ama',
            'volunteer_name' => 'Kofi Boateng',
            'applicant_name' => 'Kofi Boateng',
            'holder_name' => 'Ama Mensah',
            'amount' => Money::ofMinor(5000),
            'order_total' => Money::ofMinor(12500),
            'total' => Money::ofMinor(12500),
            'reference' => 'SCGHF-D-7K3M9Q2XZ1',
            'order_reference' => 'SCGHF-O-4H8N2P6VQ3',
            'receipt_number' => 'SCGHF-R-2026-000123',
            'invoice_number' => 'SCGHF-I-2026-000045',
            'cause_name' => 'Bongo borehole appeal',
            'event_name' => 'Harvest thanksgiving dinner',
            'event_date' => 'Saturday 14 November 2026, 18:00',
            'event_venue' => 'Bolgatanga Community Hall',
            'courier' => 'Yango Delivery',
            'tracking_reference' => 'YD-20261114-0042',
            'status_label' => 'Out for delivery',
            'status_message' => 'It is with the courier today. Please keep your phone nearby.',
            'delivery_address' => 'House 12, Ring Road, near the filling station, Madina, Accra, Greater Accra',
            'interval' => 'month',
            'next_charge_date' => now()->addMonth()->format('j F Y'),
            'expires_on' => now()->addDays(30)->format('j F Y'),
            'download_limit' => '5',
            'threshold' => '5',
            'tribute_kind' => 'in memory of',
            'tribute_name' => 'Auntie Cecilia',
            'tribute_message' => 'She taught half the village to read.',
            'code' => '482913',
            'newsletter_name' => 'the monthly update',
            'opportunity' => 'Reading volunteer',
            'role' => 'Reading volunteer',
            'department' => 'General enquiries',
            'message' => 'I would like to know more about sponsoring a child.',
            'subject' => 'Sponsoring a child',
            'order_items' => new HtmlString('<ul><li>2 × Foundation mug — GH₵ 90.00</li><li>Subtotal: GH₵ 90.00</li><li>Delivery: GH₵ 25.00</li><li><strong>Total: GH₵ 115.00</strong></li></ul>'),
            'download_links' => new HtmlString('<ul><li><a href="#">Annual report 2025 (PDF)</a></li></ul>'),
            'tickets' => new HtmlString('<ul><li><strong>T-K7M2-9QX4</strong> — Ama Mensah</li><li><strong>T-P3N8-2VZ6</strong> — Ama Mensah</li></ul>'),
            'items' => new HtmlString('<ul><li>Foundation mug · Large (MUG-L) — 3 left</li></ul>'),
        ];

        if (array_key_exists($name, $known)) {
            return $known[$name];
        }

        if (str_ends_with($name, '_url') || str_ends_with($name, '_link')) {
            return url('/example');
        }

        if (str_ends_with($name, '_date') || str_ends_with($name, '_on') || str_ends_with($name, '_at')) {
            return now()->format('j F Y');
        }

        return '['.$name.']';
    }
}
