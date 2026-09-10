<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Support\Identity\Identity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read Identity $resource
 */
final class IdentityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'user' => UserResource::make($this->resource->user)->toArray($request),
            'tenant' => TenantResource::make($this->resource->tenant)->toArray($request),

            // The client renders from this list rather than from a role name, for
            // the same reason the policies read it: the mapping from role to
            // permission is data and may change without the client changing.
            'permissions' => $this->resource->permissions,
        ];
    }
}
