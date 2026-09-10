<?php

declare(strict_types=1);

namespace App\Support\Identity;

use App\Models\Tenant;
use Illuminate\Support\Str;

/**
 * The format of an ingest credential, in one place: `pbk_<tenant>_<secret>`.
 *
 * The tenant's own ULID is part of the token, which is what lets authentication
 * establish the tenant *before* it looks the credential up. Without it the lookup
 * would have to run with no tenant current — and a table that has to be readable
 * with no tenant current is a table Row Level Security cannot protect. Carrying
 * the tenant in the token is what keeps the policy on the credential table.
 *
 * It reveals nothing: the bearer already knows which tenant it is calling for.
 */
final readonly class ApiKeyToken
{
    private const PREFIX = 'pbk';

    /** 43 alphanumeric characters — 256 bits of entropy, the same as the hash. */
    private const SECRET_LENGTH = 43;

    private function __construct(public string $tenantUlid, public string $secret) {}

    public static function issueFor(Tenant $tenant): self
    {
        return new self(
            Str::after((string) $tenant->public_id, '_'),
            Str::random(self::SECRET_LENGTH),
        );
    }

    /**
     * Null for anything that is not a token of ours — a bearer token from another
     * system, a truncated copy and paste, an empty header. The caller turns all of
     * them into the same 401 that a wrong secret produces.
     */
    public static function parse(string $value): ?self
    {
        $pattern = '/^'.self::PREFIX.'_([0-9a-hjkmnp-tv-z]{26})_([A-Za-z0-9]{'.self::SECRET_LENGTH.'})$/';

        if (preg_match($pattern, $value, $matches) !== 1) {
            return null;
        }

        return new self($matches[1], $matches[2]);
    }

    public function tenantPublicId(): string
    {
        return Tenant::publicIdPrefix().'_'.$this->tenantUlid;
    }

    public function toString(): string
    {
        return self::PREFIX.'_'.$this->tenantUlid.'_'.$this->secret;
    }

    /**
     * SHA-256, not bcrypt. The input is 256 bits of randomness, so there is no
     * dictionary to slow an attacker down with, and this runs on the hot path of
     * every ingest request.
     */
    public function hash(): string
    {
        return hash('sha256', $this->toString());
    }

    public function lastFour(): string
    {
        return substr($this->secret, -4);
    }
}
