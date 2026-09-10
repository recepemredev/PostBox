<?php

declare(strict_types=1);

use App\Exceptions\MissingTenantContext;
use App\Models\Membership;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\MassAssignmentException;

/*
 * The first of the three layers: the global scope on reads and the tenant stamp
 * on writes. The database layer underneath is asserted separately, with this one
 * switched off.
 *
 * Memberships are the subject because they are the first tenant-owned table in
 * the system. Nothing here is specific to them.
 */

beforeEach(function (): void {
    $this->acme = tenantNamed('Acme');
    $this->globex = tenantNamed('Globex');

    $this->ada = memberOf($this->acme);
    $this->bob = memberOf($this->globex);
});

it('reads only the rows of the tenant that is current', function (): void {
    $acme = forTenant($this->acme, fn (): array => Membership::query()->pluck('user_id')->all());
    $globex = forTenant($this->globex, fn (): array => Membership::query()->pluck('user_id')->all());

    expect($acme)->toBe([$this->ada->id])
        ->and($globex)->toBe([$this->bob->id]);
});

it('stamps the tenant of the context on a new row', function (): void {
    $grace = User::factory()->create();

    $membership = forTenant($this->acme, fn (): Membership => Membership::factory()->create([
        'user_id' => $grace->id,
    ]));

    expect($membership->tenant_id)->toBe($this->acme->id);
});

it('refuses a tenant supplied by the caller instead of honouring it', function (): void {
    $grace = User::factory()->create();
    $role = forTenant($this->acme, fn (): int => Membership::query()->firstOrFail()->role_id);

    expect(fn () => forTenant($this->acme, fn (): Membership => Membership::query()->create([
        'user_id' => $grace->id,
        'role_id' => $role,
        'tenant_id' => $this->globex->id,
    ])))->toThrow(MassAssignmentException::class);
});

it('refuses to write anything when no tenant is current', function (): void {
    app(TenantContext::class)->forget();

    $grace = User::factory()->create();

    expect(fn (): Membership => Membership::factory()->create(['user_id' => $grace->id]))
        ->toThrow(MissingTenantContext::class);
});

it('updates nothing when the row belongs to another tenant', function (): void {
    $foreign = forTenant($this->globex, fn (): Membership => Membership::query()->firstOrFail());

    $updated = forTenant($this->acme, fn (): int => Membership::query()
        ->whereKey($foreign->id)
        ->update(['role_id' => $foreign->role_id]));

    expect($updated)->toBe(0);
});

it('deletes nothing when the row belongs to another tenant', function (): void {
    $foreign = forTenant($this->globex, fn (): Membership => Membership::query()->firstOrFail());

    $deleted = forTenant($this->acme, fn (): int => Membership::query()->whereKey($foreign->id)->delete());

    expect($deleted)->toBe(0)
        ->and(forTenant($this->globex, fn (): int => Membership::query()->count()))->toBe(1);
});
