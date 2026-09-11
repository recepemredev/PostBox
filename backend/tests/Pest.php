<?php

declare(strict_types=1);

use App\Actions\Health\CheckSystemHealth;
use App\Actions\Identity\IssueApiKey;
use App\Enums\RoleSlug;
use App\Models\Application;
use App\Models\Endpoint;
use App\Models\EndpointSubscription;
use App\Models\EventType;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Identity\IssuedApiKey;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\MigrationRepositoryInterface;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Redis\Connections\Connection as RedisConnection;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

// TestCase applies RefreshDatabase itself, so that it can migrate as the owning
// role while the test queries as the application role.
pest()->extend(TestCase::class)->in('Feature');

/**
 * Builds the health action from healthy doubles, replacing only the collaborator a
 * test wants to break. One builder with four parameters rather than one variant per
 * failure mode.
 */
function healthAction(
    ?DatabaseManager $database = null,
    ?RedisFactory $redis = null,
    ?QueueFactory $queue = null,
    ?Migrator $migrator = null,
): CheckSystemHealth {
    return new CheckSystemHealth(
        $database ?? reachableDatabase(),
        $redis ?? reachableRedis(),
        $queue ?? reachableQueue(),
        $migrator ?? migratedSchema(),
    );
}

function reachableDatabase(): DatabaseManager
{
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getPdo')->andReturn(Mockery::mock(PDO::class));

    $database = Mockery::mock(DatabaseManager::class);
    $database->shouldReceive('connection')->andReturn($connection);

    return $database;
}

function reachableRedis(): RedisFactory
{
    $connection = Mockery::mock(RedisConnection::class);
    $connection->shouldReceive('command')->with('ping', [])->andReturn(true);

    $redis = Mockery::mock(RedisFactory::class);
    $redis->shouldReceive('connection')->andReturn($connection);

    return $redis;
}

function reachableQueue(): QueueFactory
{
    $connection = Mockery::mock(Queue::class);
    $connection->shouldReceive('size')->andReturn(0);

    $queue = Mockery::mock(QueueFactory::class);
    $queue->shouldReceive('connection')->andReturn($connection);

    return $queue;
}

/**
 * A migrator whose defined migrations have all been run.
 *
 * @param  array<string, string>  $defined
 * @param  list<string>  $ran
 */
function migratedSchema(array $defined = [], array $ran = []): Migrator
{
    $repository = Mockery::mock(MigrationRepositoryInterface::class);
    $repository->shouldReceive('getRan')->andReturn($ran);

    $migrator = Mockery::mock(Migrator::class);
    $migrator->shouldReceive('repositoryExists')->andReturnTrue();
    $migrator->shouldReceive('paths')->andReturn([]);
    $migrator->shouldReceive('getMigrationFiles')->andReturn($defined);
    $migrator->shouldReceive('getRepository')->andReturn($repository);

    return $migrator;
}

/**
 * The names of the probes that came back degraded, so a test can assert that
 * exactly one dependency failed rather than counting on probe order.
 *
 * @return list<string>
 */
function degradedChecks(TestResponse $response): array
{
    /** @var list<array{name: string, status: string}> $checks */
    $checks = $response->json('checks');

    return array_values(array_map(
        static fn (array $check): string => $check['name'],
        array_filter($checks, static fn (array $check): bool => $check['status'] === 'degraded'),
    ));
}

/*
|--------------------------------------------------------------------------
| Tenancy
|--------------------------------------------------------------------------
|
| Every tenant-owned row is written for the tenant that is current, so a test
| says which tenant it is acting for rather than passing an identifier around.
| That is not a testing convenience — it is the same path the application takes.
|
*/

function tenantNamed(string $name): Tenant
{
    return Tenant::factory()->create(['name' => $name]);
}

/**
 * Runs a callback with a tenant current, and restores whatever was current.
 *
 * @template TReturn
 *
 * @param  Closure(): TReturn  $callback
 * @return TReturn
 */
function forTenant(Tenant $tenant, Closure $callback): mixed
{
    return app(TenantContext::class)->runFor($tenant, $callback);
}

/**
 * A user who belongs to a tenant, in the role given.
 */
function memberOf(Tenant $tenant, RoleSlug $role = RoleSlug::Admin): User
{
    $user = User::factory()->create();

    forTenant($tenant, static function () use ($user, $role): void {
        Membership::factory()->withRole($role)->create(['user_id' => $user->id]);
    });

    return $user;
}

/**
 * Mints a credential through the only code path that mints credentials. A test
 * that assembled the row itself would be asserting against a second definition
 * of what a key is.
 */
function issueKeyFor(Tenant $tenant, User $creator, ?CarbonImmutable $expiresAt = null): IssuedApiKey
{
    return forTenant($tenant, static fn (): IssuedApiKey => app(IssueApiKey::class)->handle('deploy', $creator, $expiresAt));
}

/*
|--------------------------------------------------------------------------
| Ingest
|--------------------------------------------------------------------------
|
| A producer needs three things before it can publish: a credential, an
| application to publish into, and a registered event type. The helpers below
| build exactly that, and send the request the way a producer would.
|
*/

/**
 * An application and one registered event type, under the given tenant.
 *
 * @return array{Application, EventType}
 */
function registerProducer(Tenant $tenant, string $eventType = 'invoice.paid'): array
{
    return forTenant($tenant, fn (): array => [
        Application::factory()->create(),
        EventType::factory()->create(['name' => $eventType]),
    ]);
}

/**
 * An endpoint of the given application, subscribed to the given event type.
 */
function subscribedEndpoint(Application $application, EventType $eventType, bool $enabled = true): Endpoint
{
    $factory = Endpoint::factory();

    $endpoint = ($enabled ? $factory : $factory->disabled())
        ->create(['application_id' => $application->id]);

    EndpointSubscription::factory()->create([
        'endpoint_id' => $endpoint->id,
        'event_type_id' => $eventType->id,
    ]);

    return $endpoint;
}

/**
 * The body every ingest test sends unless it is testing the body itself.
 *
 * @return array<string, mixed>
 */
function invoicePaid(): array
{
    return ['event_type' => 'invoice.paid', 'payload' => ['total' => 4200]];
}

/**
 * Publishes as a producer would: an API key, and the application in the path.
 * The token and the application default to the ones the test established.
 *
 * @param  array<string, mixed>  $body
 * @param  array<string, string>  $headers
 */
function publishEvent(array $body, ?string $token = null, ?string $applicationId = null, array $headers = []): TestResponse
{
    /** @var TestCase $test */
    $test = test();

    return $test
        ->withToken($token ?? $test->token)
        ->postJson('/api/v1/apps/'.($applicationId ?? $test->application->public_id).'/messages', $body, $headers);
}
