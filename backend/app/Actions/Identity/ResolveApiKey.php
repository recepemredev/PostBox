<?php

declare(strict_types=1);

namespace App\Actions\Identity;

use App\Models\ApiKey;
use App\Models\Tenant;
use App\Support\Identity\ApiKeyToken;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\AuthenticationException;

/**
 * Turns a bearer token into the tenant the rest of the request acts for.
 *
 * The order matters. The tenant comes out of the token itself, so it is current
 * before the credential table is read — which is what allows that table to stay
 * behind a Row Level Security policy like every other tenant-owned table. Naming
 * a tenant proves nothing on its own: a token that names one and then fails to
 * match a row leaves with the same 401 as a token that names none, and with no
 * tenant bound.
 */
final readonly class ResolveApiKey
{
    public function __construct(private TenantContext $context) {}

    /**
     * @throws AuthenticationException for a malformed, unknown, revoked, expired
     *                                 or foreign token — deliberately one failure
     *                                 with one message
     */
    public function handle(?string $bearer): ApiKey
    {
        $token = ApiKeyToken::parse($bearer ?? '');

        if (! $token instanceof ApiKeyToken) {
            throw $this->rejected();
        }

        $tenant = Tenant::query()->where('public_id', $token->tenantPublicId())->first();

        if (! $tenant instanceof Tenant) {
            throw $this->rejected();
        }

        $this->context->set($tenant);

        $key = ApiKey::query()->usable()->where('token_hash', $token->hash())->first();

        if (! $key instanceof ApiKey) {
            // Nothing was authenticated, so nothing may stay bound: a later query
            // in this request must not find itself inside a tenant by accident.
            $this->context->forget();

            throw $this->rejected();
        }

        $this->markUsed($key);

        return $key;
    }

    /**
     * Last use is worth knowing — a key nobody has called in six months is a key
     * to revoke — but not worth a write on every ingest request. A minute of
     * resolution costs one update per key per minute instead of one per call.
     */
    private function markUsed(ApiKey $key): void
    {
        $now = CarbonImmutable::now();

        if ($key->last_used_at !== null && $key->last_used_at->greaterThan($now->subMinute())) {
            return;
        }

        $key->forceFill(['last_used_at' => $now])->save();
    }

    private function rejected(): AuthenticationException
    {
        return new AuthenticationException('The API key is missing, malformed or no longer valid.');
    }
}
