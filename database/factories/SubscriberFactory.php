<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Subscriber;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscriber>
 */
class SubscriberFactory extends Factory
{
    protected $model = Subscriber::class;

    /**
     * Only the columns Subscriber allows to be mass-assigned.
     *
     * Status and the tokens are deliberately not fillable on the model — a
     * subscriber must not be able to arrive confirmed straight from a request —
     * so the states below go through the model's own methods rather than
     * writing the columns directly. A factory that forced them would be testing
     * a path no real signup can take.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'name' => fake()->name(),
            'source' => 'footer',
        ];
    }

    /**
     * Pending is the default, because that is what a real signup produces. A
     * factory handing out confirmed subscribers would let tests pass while the
     * double opt-in was broken.
     */
    public function confirmed(): static
    {
        return $this->afterCreating(fn (Subscriber $subscriber) => $subscriber->confirm());
    }

    public function unsubscribed(): static
    {
        return $this->afterCreating(
            fn (Subscriber $subscriber) => $subscriber->unsubscribe('No longer interested.')
        );
    }

    /** @param array<int, string> $topics */
    public function wanting(array $topics): static
    {
        return $this->state(fn (): array => ['topics' => $topics]);
    }
}
