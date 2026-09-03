<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\EmailTemplate;
use App\Models\Newsletter;
use Illuminate\Database\Seeder;

/**
 * The lists somebody can subscribe to.
 *
 * Three rather than one, because `subscribers.topics` already promises that
 * choice — somebody can take the impact update without the fundraising appeals.
 * A single list would make that column decorative, and a supporter who was
 * promised quarterly impact news and receives weekly appeals unsubscribes.
 *
 * Cadence is stated on every list and shown at signup. It is a promise, and the
 * cheapest way to generate spam complaints is to break it.
 */
class NewsletterSeeder extends Seeder
{
    public function run(): void
    {
        $wrapper = EmailTemplate::query()->where('key', 'newsletter.campaign')->first();

        $lists = [
            [
                'key' => 'impact-update',
                'topic' => 'impact',
                'name' => 'Impact update',
                'description' => 'What the Foundation has been doing, and what it changed. '
                    .'No appeals.',
                'cadence' => 'quarterly',
                'sort_order' => 1,
            ],
            [
                'key' => 'appeals',
                'topic' => 'appeals',
                'name' => 'Appeals and campaigns',
                'description' => 'Fundraising appeals for specific causes.',
                'cadence' => 'occasional',
                'sort_order' => 2,
            ],
            [
                'key' => 'events',
                'topic' => 'events',
                'name' => 'Events and outreach',
                'description' => 'Upcoming events, outreach programmes and volunteering days.',
                'cadence' => 'monthly',
                'sort_order' => 3,
            ],
        ];

        foreach ($lists as $definition) {
            $newsletter = Newsletter::firstOrNew(['key' => $definition['key']]);

            $newsletter->forceFill([
                'key' => $definition['key'],
                'topic' => $definition['topic'],
                'email_template_id' => $wrapper?->getKey(),
                'sort_order' => $definition['sort_order'],
            ]);

            // Wording is the Foundation's once it has been edited.
            if (! $newsletter->exists) {
                $newsletter->forceFill([
                    'name' => $definition['name'],
                    'description' => $definition['description'],
                    'cadence' => $definition['cadence'],
                    'is_active' => true,
                ]);
            }

            $newsletter->save();
        }
    }
}
