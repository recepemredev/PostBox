<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AuditLog>
 */
final class AuditLogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'actor_id' => User::factory(),
            'action' => 'endpoint.updated',
            'entity_type' => 'endpoint',
            'entity_id' => fake()->numberBetween(1, 1000),
            'entity_public_id' => 'ep_'.Str::lower((string) Str::ulid()),
            'changes' => ['status' => ['before' => 'enabled', 'after' => 'disabled']],
            'ip_address' => fake()->ipv4(),
        ];
    }
}
