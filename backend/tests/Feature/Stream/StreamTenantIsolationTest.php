<?php

declare(strict_types=1);

use App\Actions\Stream\StreamAttempts;
use App\Models\Concerns\TenantScope;
use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Models\User;
use App\Support\Delivery\TransportResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;

/**
 * The live stream is a read of delivery_attempts — the same data the
 * attempt inspector (Step 14) reads. Tenant isolation is not a new
 * boundary; it is the same three-layer design (global scope, write-time
 * assignment, Row Level Security) proven on every other tenant-owned
 * table, exercised here against StreamAttempts::poll() and the route
 * built on top of it.
 */
beforeEach(function (): void {
    [$this->acme, , $this->acmeDelivery] = publishedDelivery('Acme');
    fakeTransport(TransportResult::responded(200, [], 'ok', 5));
    attemptDelivery($this->acme, $this->acmeDelivery);

    [$this->globex, , $this->globexDelivery] = publishedDelivery('Globex');
    fakeTransport(TransportResult::responded(200, [], 'ok', 5));
    attemptDelivery($this->globex, $this->globexDelivery);

    $this->action = app(StreamAttempts::class);
});

it('another tenant\'s attempt never appears in a poll, with the global scope active', function (): void {
    $page = forTenant($this->acme, fn () => $this->action->poll(null, CarbonImmutable::now()->addSeconds(5)));

    $publicIds = $page->items->pluck('public_id');
    $globexAttemptId = forTenant($this->globex, fn (): string => DeliveryAttempt::query()->sole()->public_id);

    expect($publicIds)->not->toContain($globexAttemptId)
        ->and($page->items)->toHaveCount(1);
});

it('another tenant\'s attempt is hidden even with the global scope deliberately disabled, because Row Level Security still refuses it', function (): void {
    $rows = forTenant($this->acme, fn (): int => DeliveryAttempt::query()
        ->withoutGlobalScope(TenantScope::class)
        ->where('tenant_id', $this->globex->id)
        ->count());

    expect($rows)->toBe(0);
});

it('is denied at the Gate for a user with no membership in any tenant, the same "no permission" case PermissionsTest already proves', function (): void {
    $stranger = User::factory()->create();

    forTenant($this->acme, function () use ($stranger): void {
        expect(Gate::forUser($stranger)->allows('viewAny', Delivery::class))->toBeFalse();
    });
});

it('an unauthenticated request gets 401 JSON, never a redirect', function (): void {
    $this->getJson('/api/v1/stream')
        ->assertUnauthorized()
        ->assertJsonStructure(['message']);
});
