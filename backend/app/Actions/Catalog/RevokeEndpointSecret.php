<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

use App\Actions\Audit\RecordAuditEntry;
use App\Models\EndpointSecret;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Revokes a secret, and says when rather than deleting the row — the same
 * shape RevokeApiKey takes, for the same reason: delivery history signed
 * with this secret still points at it. Revoking twice is the same as
 * revoking once, and only the first call writes an audit entry.
 */
final readonly class RevokeEndpointSecret
{
    public function __construct(private RecordAuditEntry $auditor) {}

    /**
     * $endpointPublicId comes from the caller rather than $secret->endpoint:
     * the controller already has the endpoint the route named, and reading
     * the relation here would be a lazy load Model::shouldBeStrict() refuses
     * outside production.
     */
    public function handle(EndpointSecret $secret, string $endpointPublicId, User $actor, ?string $ipAddress): EndpointSecret
    {
        if ($secret->revoked_at === null) {
            $secret->forceFill(['revoked_at' => CarbonImmutable::now()])->save();

            $this->auditor->handle(
                action: 'endpoint_secret.revoked',
                entityType: 'endpoint_secret',
                entityId: $secret->id,
                entityPublicId: $secret->public_id,
                changes: ['endpoint_id' => $endpointPublicId],
                actor: $actor,
                ipAddress: $ipAddress,
            );
        }

        return $secret;
    }
}
