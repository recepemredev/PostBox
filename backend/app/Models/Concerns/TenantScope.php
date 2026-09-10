<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Adds the tenant predicate to every query on a tenant-owned model.
 *
 * This is the layer that keeps ordinary code honest; Row Level Security is the
 * layer that keeps this one honest. A test that removes this scope and still
 * cannot read another tenant's rows is what proves the second layer exists.
 */
final class TenantScope implements Scope
{
    public function __construct(private readonly TenantContext $context) {}

    public function apply(Builder $builder, Model $model): void
    {
        $tenant = $this->context->current();

        if ($tenant === null) {
            /*
             * No tenant is current, so nothing is in scope. The column is NOT
             * NULL, which makes this an empty result rather than a filter that
             * happens to match nothing today.
             */
            $builder->whereNull($model->qualifyColumn('tenant_id'));

            return;
        }

        $builder->where($model->qualifyColumn('tenant_id'), $tenant->getKey());
    }
}
