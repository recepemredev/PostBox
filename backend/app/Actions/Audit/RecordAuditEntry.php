<?php

declare(strict_types=1);

namespace App\Actions\Audit;

use App\Models\AuditLog;
use App\Models\User;

/**
 * Writes one row to the audit log. Extracted at the second call site
 * (CLAUDE.md's own rule): TransitionBreaker used to call `AuditLog::create()`
 * directly, and Catalog's endpoint and secret mutations (Step 13) need the
 * same write with an actor and an address a breaker transition never has —
 * a worker acting on its own has no person and no request behind it, so both
 * stay optional here rather than being asked of every caller.
 */
final readonly class RecordAuditEntry
{
    /**
     * @param  array<string, mixed>|null  $changes
     */
    public function handle(
        string $action,
        string $entityType,
        int $entityId,
        ?string $entityPublicId,
        ?array $changes = null,
        ?User $actor = null,
        ?string $ipAddress = null,
    ): void {
        AuditLog::create([
            'actor_id' => $actor?->id,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'entity_public_id' => $entityPublicId,
            'changes' => $changes,
            'ip_address' => $ipAddress,
        ]);
    }
}
