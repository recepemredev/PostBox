<?php

declare(strict_types=1);

use App\Models\Application;
use Illuminate\Database\QueryException;

/*
 * An application's only rule at this layer is that its name identifies it within
 * a tenant and nowhere wider. The tenant scoping of that constraint is the
 * property worth a test: a global unique would let one tenant's naming choice
 * collide with another's.
 */

beforeEach(function (): void {
    $this->acme = tenantNamed('Acme');
    $this->globex = tenantNamed('Globex');
});

it('rejects a second application with a name already taken in the tenant', function (): void {
    forTenant($this->acme, fn (): Application => Application::factory()->create(['name' => 'Billing']));

    expect(fn () => forTenant($this->acme, fn (): Application => Application::factory()->create(['name' => 'Billing'])))
        ->toThrow(QueryException::class);
});

it('lets two tenants name an application the same', function (): void {
    $acme = forTenant($this->acme, fn (): Application => Application::factory()->create(['name' => 'Billing']));
    $globex = forTenant($this->globex, fn (): Application => Application::factory()->create(['name' => 'Billing']));

    expect($acme->tenant_id)->toBe($this->acme->id)
        ->and($globex->tenant_id)->toBe($this->globex->id);
});
