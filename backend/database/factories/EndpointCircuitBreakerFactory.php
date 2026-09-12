<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BreakerState;
use App\Models\Endpoint;
use App\Models\EndpointCircuitBreaker;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A breaker is written for whichever tenant is current; its endpoint is
 * created under the same tenant unless the test names one.
 *
 * @extends Factory<EndpointCircuitBreaker>
 */
final class EndpointCircuitBreakerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'endpoint_id' => Endpoint::factory(),
            'state' => BreakerState::Open,
            'state_changed_at' => now(),
            'opened_at' => now(),
            'probe_started_at' => null,
        ];
    }

    public function halfOpen(): self
    {
        return $this->state(fn (): array => [
            'state' => BreakerState::HalfOpen,
            'probe_started_at' => now(),
        ]);
    }

    public function closed(): self
    {
        return $this->state(fn (): array => [
            'state' => BreakerState::Closed,
            'opened_at' => null,
            'probe_started_at' => null,
        ]);
    }
}
