<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AttemptOutcome;
use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Models\Endpoint;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeliveryAttempt>
 */
final class DeliveryAttemptFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'delivery_id' => Delivery::factory(),
            'endpoint_id' => Endpoint::factory(),
            'attempt_number' => 1,
            'outcome' => AttemptOutcome::Succeeded,
            'request_headers' => ['Content-Type' => 'application/json'],
            'request_body' => '{"example":true}',
            'response_status' => 200,
            'response_headers' => ['Content-Type' => 'application/json'],
            'response_body' => '{"received":true}',
            'duration_ms' => fake()->numberBetween(10, 500),
        ];
    }

    public function timedOut(): self
    {
        return $this->state(fn (): array => [
            'outcome' => AttemptOutcome::Timeout,
            'response_status' => null,
            'response_headers' => null,
            'response_body' => null,
            'error_message' => 'The endpoint did not respond within the configured timeout.',
        ]);
    }

    public function failed(int $status = 500): self
    {
        return $this->state(fn (): array => [
            'outcome' => AttemptOutcome::Failed,
            'response_status' => $status,
            'response_body' => '{"error":"server_error"}',
        ]);
    }
}
