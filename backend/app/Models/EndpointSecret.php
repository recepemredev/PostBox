<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\Expirable;
use App\Models\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Database\Factories\EndpointSecretFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One HMAC signing key for an endpoint. During rotation an endpoint holds several
 * at once: the new key is created live, the previous one is given an expiry a
 * little way out, and until that passes every request carries a signature from
 * each. The signature header lists them all.
 *
 * The key is stored encrypted, returned once at creation, and never logged or
 * shown again — hence hidden here and absent from $fillable, so it can only be
 * set deliberately.
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property int $endpoint_id
 * @property string $secret
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $revoked_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Endpoint $endpoint
 */
final class EndpointSecret extends Model
{
    /** @use HasFactory<EndpointSecretFactory> */
    use BelongsToTenant, Expirable, HasFactory, HasPublicId;

    /** @var list<string> */
    protected $fillable = [];

    /** @var list<string> */
    protected $hidden = ['secret'];

    public static function publicIdPrefix(): string
    {
        return 'sec';
    }

    /**
     * @return BelongsTo<Endpoint, $this>
     */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(Endpoint::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }
}
