<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

use App\Actions\Audit\RecordAuditEntry;
use App\Enums\EndpointStatus;
use App\Models\Endpoint;
use App\Models\User;
use BackedEnum;

final readonly class UpdateEndpoint
{
    public function __construct(private RecordAuditEntry $auditor) {}

    /**
     * @param  array{name?: string, url?: string, status?: EndpointStatus}  $changes
     */
    public function handle(Endpoint $endpoint, array $changes, User $actor, ?string $ipAddress): Endpoint
    {
        $keys = array_keys($changes);
        $before = $this->snapshot($endpoint, $keys);

        $endpoint->update($changes);

        $after = $this->snapshot($endpoint, $keys);

        $this->auditor->handle(
            action: 'endpoint.updated',
            entityType: 'endpoint',
            entityId: $endpoint->id,
            entityPublicId: $endpoint->public_id,
            changes: ['before' => $before, 'after' => $after],
            actor: $actor,
            ipAddress: $ipAddress,
        );

        return $endpoint;
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private function snapshot(Endpoint $endpoint, array $keys): array
    {
        $values = [];

        foreach ($keys as $key) {
            $value = $endpoint->{$key};
            $values[$key] = $value instanceof BackedEnum ? $value->value : $value;
        }

        return $values;
    }
}
