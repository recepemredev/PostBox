<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Endpoint;
use App\Models\EndpointSecret;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<EndpointSecret>
 */
final class EndpointSecretFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'endpoint_id' => Endpoint::factory(),
            'secret' => 'whsec_'.Str::random(40),
        ];
    }

    public function expired(): self
    {
        return $this->state(fn (): array => ['expires_at' => now()->subHour()]);
    }

    public function revoked(): self
    {
        return $this->state(fn (): array => ['revoked_at' => now()->subHour()]);
    }
}
