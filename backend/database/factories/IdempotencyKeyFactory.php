<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\IdempotencyKey;
use App\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IdempotencyKey>
 */
final class IdempotencyKeyFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => fake()->unique()->uuid(),
            'request_hash' => hash('sha256', fake()->uuid()),
            'message_id' => Message::factory(),
            'expires_at' => now()->addDay(),
        ];
    }
}
