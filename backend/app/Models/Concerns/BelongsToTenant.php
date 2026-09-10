<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Exceptions\MissingTenantContext;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Two of the three layers of the tenant boundary: the global scope on reads, and
 * the tenant stamp on writes.
 *
 * The stamp is applied unconditionally, overwriting whatever the attribute held.
 * A tenant is never something a caller supplies — not from a request, not from a
 * payload — so a value already sitting there is either a mistake or an attempt,
 * and both deserve the same treatment. Writing for another tenant deliberately
 * goes through TenantContext::runFor().
 *
 * @phpstan-require-extends Model
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(app(TenantScope::class));

        static::creating(static function (Model $model): void {
            $tenant = app(TenantContext::class)->current();

            if ($tenant === null) {
                throw MissingTenantContext::forWrite($model::class);
            }

            $model->setAttribute('tenant_id', $tenant->getKey());
        });
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
