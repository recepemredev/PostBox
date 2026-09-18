<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

use App\Actions\Audit\RecordAuditEntry;
use App\Enums\EndpointStatus;
use App\Models\Application;
use App\Models\Endpoint;
use App\Models\User;

/**
 * Endpoint creation is a catalog change CLAUDE.md's audit rule names
 * explicitly ("endpoint and secret changes"), so it is written here rather
 * than left to whichever screen happens to call it.
 */
final readonly class CreateEndpoint
{
    public function __construct(private RecordAuditEntry $auditor) {}

    public function handle(Application $application, string $name, string $url, User $actor, ?string $ipAddress): Endpoint
    {
        $endpoint = $application->endpoints()->create([
            'name' => $name,
            'url' => $url,
            'status' => EndpointStatus::Enabled,
        ]);

        $this->auditor->handle(
            action: 'endpoint.created',
            entityType: 'endpoint',
            entityId: $endpoint->id,
            entityPublicId: $endpoint->public_id,
            changes: ['name' => $name, 'url' => $url],
            actor: $actor,
            ipAddress: $ipAddress,
        );

        return $endpoint;
    }
}
