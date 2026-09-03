<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'customer_name' => fake()->name(),
            'customer_email' => fake()->unique()->safeEmail(),
            'customer_phone' => '+2332'.fake()->numerify('#######'),
            'status' => OrderStatus::Pending,
            // GH₵ 90.00 of goods, GH₵ 25.00 delivery
            'subtotal' => 9_000,
            'shipping' => 2_500,
            'discount' => 0,
            'total' => 11_500,
            'currency' => 'GHS',
            'delivery_region' => 'Greater Accra',
        ];
    }

    public function paid(): static
    {
        return $this->state(fn (): array => [
            'status' => OrderStatus::Paid,
            'paid_at' => now(),
        ]);
    }

    public function pickup(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_pickup' => true,
            'shipping' => 0,
            'total' => $attributes['subtotal'] ?? 9_000,
        ]);
    }
}
