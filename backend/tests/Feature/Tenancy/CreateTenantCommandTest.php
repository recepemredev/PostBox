<?php

declare(strict_types=1);

use App\Enums\RoleSlug;
use App\Models\Concerns\TenantScope;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;

it('creates a tenant, its first administrator and the membership between them', function (): void {
    $this->artisan('postbox:tenant', [
        '--name' => 'Acme Industries',
        '--user' => 'Ada Lovelace',
        '--email' => 'ada@acme.test',
    ])->assertSuccessful();

    $tenant = Tenant::query()->sole();
    $user = User::query()->sole();

    expect($tenant->name)->toBe('Acme Industries')
        ->and($tenant->public_id)->toStartWith('ten_')
        ->and($user->public_id)->toStartWith('usr_');

    $membership = forTenant($tenant, fn (): Membership => Membership::query()->with('role')->sole());

    expect($membership->user_id)->toBe($user->id)
        ->and($membership->role->slug)->toBe(RoleSlug::Admin->value);
});

it('refuses an address that already signed up', function (): void {
    User::factory()->create(['email' => 'ada@acme.test']);

    $this->artisan('postbox:tenant', [
        '--name' => 'Acme',
        '--user' => 'Ada',
        '--email' => 'ada@acme.test',
    ])->assertFailed();

    expect(Tenant::query()->count())->toBe(0);
});

it('refuses to run without the details it needs', function (): void {
    $this->artisan('postbox:tenant', ['--name' => 'Acme'])->assertFailed();

    expect(Tenant::query()->count())->toBe(0)
        ->and(Membership::query()->withoutGlobalScope(TenantScope::class)->count())->toBe(0);
});
