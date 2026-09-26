<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Newsletter;
use App\Models\NewsletterCampaign;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NewsletterCampaign>
 */
class NewsletterCampaignFactory extends Factory
{
    protected $model = NewsletterCampaign::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'newsletter_id' => Newsletter::factory(),
            'title' => fake()->sentence(4),
            'subject' => fake()->sentence(5),
            'body_html' => '<p>An update from the Foundation.</p>',
            'body_text' => 'An update from the Foundation.',
            /*
             * Status is not set here, and not fillable on the model: a campaign
             * starts as a draft, untested and unapproved, which is the state a
             * real one starts in. A factory that produced sendable campaigns
             * would let the two gates rot untested.
             */
        ];
    }
}
