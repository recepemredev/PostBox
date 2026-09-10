<?php

declare(strict_types=1);

use App\Models\ApiKey;
use App\Models\Concerns\TenantScope;
use App\Models\Membership;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantSession;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * The layer the application cannot forget.
 *
 * Every test here removes the global scope first. That is the point: what is being
 * asserted is what PostgreSQL does when the application layer is not there, which
 * is the situation a bug produces.
 */

beforeEach(function (): void {
    $this->acme = tenantNamed('Acme');
    $this->globex = tenantNamed('Globex');

    $this->ada = memberOf($this->acme);
    $this->bob = memberOf($this->globex);

    issueKeyFor($this->acme, $this->ada);
    issueKeyFor($this->globex, $this->bob);
});

it('connects as a role that can neither bypass a policy nor become a superuser', function (): void {
    $role = DB::selectOne(
        'select current_user as name, rolsuper::text as superuser, rolbypassrls::text as bypass
         from pg_roles where rolname = current_user'
    );

    expect($role->superuser)->toBe('false')
        ->and($role->bypass)->toBe('false');
});

it('hides another tenant even when the application scope is removed', function (): void {
    $rows = forTenant($this->acme, fn (): array => ApiKey::query()
        ->withoutGlobalScope(TenantScope::class)
        ->pluck('tenant_id')
        ->all());

    expect($rows)->toBe([$this->acme->id]);
});

it('hides everything when no tenant is current', function (): void {
    app(TenantContext::class)->forget();

    $rows = ApiKey::query()->withoutGlobalScope(TenantScope::class)->count();

    expect($rows)->toBe(0);
});

it('lets a user read their own memberships before a tenant is chosen', function (): void {
    app(TenantContext::class)->forget();
    app(TenantSession::class)->bindUser($this->ada->id);

    $rows = Membership::query()->withoutGlobalScope(TenantScope::class)->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()?->tenant_id)->toBe($this->acme->id);
});

it('refuses a write that names a tenant other than the current one', function (): void {
    /*
     * Straight at the query builder, past the model, the scope and the stamp —
     * the shape a raw statement or a future bug would take. The transaction this
     * test runs in is aborted by the violation, so nothing follows it.
     */
    expect(fn (): bool => forTenant($this->acme, fn (): bool => DB::table('api_keys')->insert([
        'public_id' => 'key_forged',
        'tenant_id' => $this->globex->id,
        'name' => 'forged',
        'token_hash' => str_repeat('a', 64),
        'last_four' => 'abcd',
        'created_at' => now(),
        'updated_at' => now(),
    ])))->toThrow(QueryException::class);
});
