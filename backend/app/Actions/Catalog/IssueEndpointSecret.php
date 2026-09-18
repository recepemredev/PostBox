<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

use App\Actions\Audit\RecordAuditEntry;
use App\Models\Endpoint;
use App\Models\EndpointSecret;
use App\Models\User;
use App\Support\Catalog\EndpointSecretToken;
use App\Support\Catalog\IssuedEndpointSecret;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Issuing a secret is how an endpoint rotates — there is no separate "rotate"
 * action, because a second entry point that did the same thing with a minor
 * difference is exactly the duplicate CLAUDE.md rules out. An endpoint's
 * first secret has nothing live to retire, so that call takes the same path
 * as every later one; whatever *is* currently live (Expirable::current())
 * is not revoked outright but given an expiry a configured overlap out, so
 * AttemptDelivery::activeSecrets() and Signature::sign() carry both the old
 * and the new token in PostBox-Signature until a consumer has cut over.
 */
final readonly class IssueEndpointSecret
{
    public function __construct(private RecordAuditEntry $auditor) {}

    public function handle(Endpoint $endpoint, User $actor, ?string $ipAddress): IssuedEndpointSecret
    {
        $token = EndpointSecretToken::generate();
        $now = CarbonImmutable::now();

        return DB::transaction(function () use ($endpoint, $token, $now, $actor, $ipAddress): IssuedEndpointSecret {
            $this->retireCurrentSecrets($endpoint, $now);

            $secret = new EndpointSecret;
            $secret->secret = $token->toString();
            $endpoint->secrets()->save($secret);

            $this->auditor->handle(
                action: 'endpoint_secret.issued',
                entityType: 'endpoint_secret',
                entityId: $secret->id,
                entityPublicId: $secret->public_id,
                changes: ['endpoint_id' => $endpoint->public_id, 'last_four' => $token->lastFour()],
                actor: $actor,
                ipAddress: $ipAddress,
            );

            return new IssuedEndpointSecret($secret, $token->toString());
        });
    }

    private function retireCurrentSecrets(Endpoint $endpoint, CarbonImmutable $now): void
    {
        $overlapEnds = $now->addHours(Config::integer('postbox.secrets.rotation_overlap_hours'));

        $endpoint->secrets()->current()->update(['expires_at' => $overlapEnds]);
    }
}
