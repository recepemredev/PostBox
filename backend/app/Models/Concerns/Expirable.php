<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A credential with a life: it can be revoked outright, and it can be given an
 * expiry. An API key and an endpoint's signing secret both have this shape, and
 * "is it live right now?" is the same question for each — asked in one place so a
 * lookup that must ignore the dead ones does not restate the predicate.
 *
 * @phpstan-require-extends Model
 */
trait Expirable
{
    /**
     * Neither revoked nor past its expiry. A row looked up through this scope is
     * indistinguishable from one that was never there, which is the answer an
     * authentication path wants and the one a signing path wants too.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeCurrent(Builder $query): void
    {
        $query->whereNull('revoked_at')->where(
            static fn (Builder $row): Builder => $row
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', now()),
        );
    }
}
