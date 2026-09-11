<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsTimestamps;
use App\Models\ApiKey;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Everything about a key except the one thing that matters. There is no
 * conditional here that could reveal the secret: the token is a different
 * resource, returned by exactly one endpoint, exactly once.
 *
 * @property-read ApiKey $resource
 */
final class ApiKeyResource extends JsonResource
{
    use FormatsTimestamps;

    /**
     * @return array<string, string|null>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->public_id,
            'name' => $this->resource->name,
            'last_four' => $this->resource->last_four,
            'last_used_at' => self::utc($this->resource->last_used_at),
            'expires_at' => self::utc($this->resource->expires_at),
            'revoked_at' => self::utc($this->resource->revoked_at),
            'created_at' => self::utc($this->resource->created_at),
        ];
    }
}
