<?php

declare(strict_types=1);

namespace App\Actions\Identity;

use App\Models\ApiKey;
use App\Models\User;
use App\Support\Identity\ApiKeyToken;
use App\Support\Identity\IssuedApiKey;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * Issues a credential for the tenant that is current. The secret is returned to
 * the caller and then exists nowhere else: what the row keeps is a hash, and there
 * is no code path anywhere that can turn it back.
 */
final readonly class IssueApiKey
{
    public function __construct(private TenantContext $context) {}

    public function handle(string $name, User $creator, ?CarbonImmutable $expiresAt = null): IssuedApiKey
    {
        $token = ApiKeyToken::issueFor($this->context->currentOrFail());

        $key = new ApiKey;
        $key->name = $name;
        $key->token_hash = $token->hash();
        $key->last_four = $token->lastFour();
        $key->expires_at = $expiresAt;
        $key->created_by = $creator->id;

        // tenant_id is not set here and never is: BelongsToTenant stamps it from
        // the context, and the row would be refused by the database otherwise.
        $key->save();

        return new IssuedApiKey($key, $token->toString());
    }
}
