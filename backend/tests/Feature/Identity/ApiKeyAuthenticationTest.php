<?php

declare(strict_types=1);

use App\Actions\Identity\RevokeApiKey;
use App\Models\ApiKey;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
 * The ingest credential. There is no route behind it yet — the ingest endpoint
 * arrives in Step 4 — so the middleware is exercised through a route defined here.
 * Inventing a product endpoint to have something to test would put a surface in
 * the application that nothing has asked for.
 */

const PROBE = '/v1/test-probe';

beforeEach(function (): void {
    $this->acme = tenantNamed('Acme');
    $this->globex = tenantNamed('Globex');
    $this->ada = memberOf($this->acme);
    $this->bob = memberOf($this->globex);

    Route::middleware('api-key')->get(PROBE, fn (): array => [
        'tenant' => app(TenantContext::class)->currentOrFail()->public_id,
        'keys_visible' => ApiKey::query()->count(),
    ]);
});

function probeWith(?string $token): TestResponse
{
    /** @var TestCase $test */
    $test = test();

    return $test->getJson(
        PROBE,
        $token === null ? [] : ['Authorization' => 'Bearer '.$token],
    );
}

it('accepts a valid key and acts for the tenant the key belongs to', function (): void {
    $issued = issueKeyFor($this->acme, $this->ada);

    probeWith($issued->token)
        ->assertOk()
        ->assertJsonPath('tenant', $this->acme->public_id)
        ->assertJsonPath('keys_visible', 1);
});

it('rejects a revoked key', function (): void {
    $issued = issueKeyFor($this->acme, $this->ada);

    forTenant($this->acme, fn (): ApiKey => app(RevokeApiKey::class)->handle($issued->key));

    probeWith($issued->token)->assertUnauthorized();
});

it('rejects an expired key', function (): void {
    $issued = issueKeyFor($this->acme, $this->ada, CarbonImmutable::now()->addHour());

    $this->travelTo(CarbonImmutable::now()->addHours(2));

    probeWith($issued->token)->assertUnauthorized();
});

it('rejects a missing, malformed or unknown key with the same answer', function (): void {
    $unknown = 'pbk_'.strtolower((string) Str::ulid()).'_'.Str::random(43);

    probeWith(null)->assertUnauthorized();
    probeWith('nonsense')->assertUnauthorized();
    probeWith('pbk_short_secret')->assertUnauthorized();
    probeWith($unknown)->assertUnauthorized();
});

it('rejects a key whose secret belongs to one tenant and whose prefix names another', function (): void {
    $issued = issueKeyFor($this->acme, $this->ada);

    $forged = str_replace(
        Str::after($this->acme->public_id, '_'),
        Str::after($this->globex->public_id, '_'),
        $issued->token,
    );

    probeWith($forged)->assertUnauthorized();
});

it('leaves no tenant bound when a key is rejected', function (): void {
    probeWith('pbk_'.strtolower((string) Str::ulid()).'_'.Str::random(43))
        ->assertUnauthorized();

    expect(app(TenantContext::class)->has())->toBeFalse();
});

it('records that a key was used, but not on every single call', function (): void {
    $issued = issueKeyFor($this->acme, $this->ada);

    probeWith($issued->token)->assertOk();

    $firstUse = forTenant($this->acme, fn () => ApiKey::query()->firstOrFail()->last_used_at);
    expect($firstUse)->not->toBeNull();

    probeWith($issued->token)->assertOk();

    $secondUse = forTenant($this->acme, fn () => ApiKey::query()->firstOrFail()->last_used_at);
    expect($secondUse?->equalTo($firstUse))->toBeTrue();

    $this->travelTo(CarbonImmutable::now()->addMinutes(2));
    probeWith($issued->token)->assertOk();

    $thirdUse = forTenant($this->acme, fn () => ApiKey::query()->firstOrFail()->last_used_at);
    expect($thirdUse?->greaterThan($firstUse))->toBeTrue();
});
