<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsTimestamps;
use App\Models\EndpointSecret;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Everything about a secret except the secret itself. There is no
 * conditional here that could reveal the plaintext: `last_four` is derived
 * from the decrypted value (the `encrypted` cast makes that available in
 * memory) but only its last four characters ever leave this class, the same
 * amount ApiKeyResource exposes for a credential it cannot decrypt at all.
 *
 * @property-read EndpointSecret $resource
 */
final class EndpointSecretResource extends JsonResource
{
    use FormatsTimestamps;

    /**
     * @return array<string, string|null>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->public_id,
            'last_four' => substr($this->resource->secret, -4),
            'expires_at' => self::utc($this->resource->expires_at),
            'revoked_at' => self::utc($this->resource->revoked_at),
            'created_at' => self::utc($this->resource->created_at),
        ];
    }
}
