<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Endpoint;
use App\Models\Message;
use App\Models\Replay;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Defaults to the simplest legal shape — a single message, replayed to all of
 * its original subscribers. forMessageAndEndpoint() and forRange() switch to
 * the other two shapes replays_scope_shape_check allows.
 *
 * @extends Factory<Replay>
 */
final class ReplayFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'message_id' => Message::factory(),
        ];
    }

    public function forMessageAndEndpoint(): self
    {
        return $this->state(fn (): array => ['endpoint_id' => Endpoint::factory()]);
    }

    public function forRange(): self
    {
        return $this->state(fn (): array => [
            'message_id' => null,
            'endpoint_id' => Endpoint::factory(),
            'range_from' => now()->subDay(),
            'range_to' => now(),
        ]);
    }
}
