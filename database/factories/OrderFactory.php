<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        $createdAt = CarbonImmutable::parse($this->faker->dateTimeBetween('-10 days', '-1 day'));

        return [
            'marketplace_order_id' => 'ord_'.strtoupper($this->faker->unique()->bothify('########')),
            'number' => 'MH-'.$this->faker->unique()->numberBetween(1000000, 9999999),
            'status' => OrderStatus::Created,
            'currency' => 'RUB',
            'customer_email' => $this->faker->safeEmail(),
            'customer_name' => $this->faker->name(),
            'customer_phone' => $this->faker->optional()->e164PhoneNumber(),
            'delivery_price' => '0.00',
            'discount' => '0.00',
            'total' => (string) $this->faker->numberBetween(100, 9999).'.00',
            'paid_at' => null,
            'cancelled_at' => null,
            'cancel_reason' => null,
            'marketplace_created_at' => $createdAt,
            'marketplace_updated_at' => $createdAt,
        ];
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => OrderStatus::Paid,
            'paid_at' => CarbonImmutable::parse($attributes['marketplace_created_at'])->addHour(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => OrderStatus::Cancelled,
            'cancelled_at' => CarbonImmutable::parse($attributes['marketplace_created_at'])->addHours(2),
            'cancel_reason' => 'Покупатель отказался от заказа',
        ]);
    }
}
