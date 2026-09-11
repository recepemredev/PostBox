<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Application;
use App\Models\EventType;
use App\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A message is written for whichever tenant is current. Its created_at is left
 * to Eloquent's own "now" unless a test names one — the partition a row lands in
 * is exactly what that value says.
 *
 * @extends Factory<Message>
 */
final class MessageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'application_id' => Application::factory(),
            'event_type_id' => EventType::factory(),
            'payload' => ['example' => fake()->word()],
        ];
    }
}
