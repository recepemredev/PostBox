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

    /**
     * exhausted_at is not optional here: deliveries_exhausted_shape_check holds
     * the database to "exhausted means there is a moment it gave up", so a state
     * that set only the status would build a row the schema rejects.
     */
    public function exhausted(): self
    {
        return $this->state(fn (): array => [
            'status' => DeliveryStatus::Exhausted,
            'next_attempt_at' => null,
            'exhausted_at' => now(),
            'failure_reason' => 'exhausted after 8 attempts; last outcome: failed',
        ]);
    }
}
