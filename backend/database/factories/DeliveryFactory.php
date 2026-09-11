<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DeliveryStatus;
use App\Models\Delivery;
use App\Models\Endpoint;
use App\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Delivery>
 */
final class DeliveryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'message_id' => Message::factory(),
            'endpoint_id' => Endpoint::factory(),
            'status' => DeliveryStatus::Pending,
            'next_attempt_at' => now(),
        ];
    }

    public function succeeded(): self
    {
        return $this->state(fn (): array => ['status' => DeliveryStatus::Succeeded, 'next_attempt_at' => null]);
    }

    public function exhausted(): self
    {
        return $this->state(fn (): array => ['status' => DeliveryStatus::Exhausted, 'next_attempt_at' => null]);
    }
}
