<?php

declare(strict_types=1);

use App\Actions\Health\CheckSystemHealth;
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
