<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EndpointStatus;
use App\Models\Application;
use App\Models\Endpoint;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An endpoint is written for whichever tenant is current; its application is
 * created under the same tenant unless the test names one.
 *
 * @extends Factory<Endpoint>
 */
final class EndpointFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'application_id' => Application::factory(),
            'name' => fake()->unique()->words(2, true),
            'url' => fake()->url(),
            'status' => EndpointStatus::Enabled,
        ];
    }

    public function disabled(): self
    {
        return $this->state(fn (): array => ['status' => EndpointStatus::Disabled]);
    }
}
