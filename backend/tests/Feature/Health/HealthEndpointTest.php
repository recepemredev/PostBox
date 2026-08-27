<?php

declare(strict_types=1);

use App\Actions\Health\CheckSystemHealth;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Database\DatabaseManager;

/*
 * The healthy path runs against the real PostgreSQL and Redis the test environment
 * points at — a green result there means the probes actually work. The failure
 * paths swap the action for one built on doubles, because the point of each is that
 * one specific dependency is unreachable.
 */

it('reports every dependency as healthy against the real stack', function (): void {
    $response = $this->getJson('/api/health');

    $response->assertOk()->assertJsonPath('status', 'ok');

    /** @var list<array{name: string, status: string, duration_ms: int}> $checks */
    $checks = $response->json('checks');

    expect(array_column($checks, 'name'))
        ->toEqualCanonicalizing(['database', 'redis', 'queue', 'migrations'])
        ->and(array_column($checks, 'status'))->each->toBe('ok')
        ->and(array_column($checks, 'duration_ms'))->each->toBeInt()
        ->and($response->json('duration_ms'))->toBeInt();
});

it('answers 503 when the database is unreachable', function (): void {
    $database = Mockery::mock(DatabaseManager::class);
    $database->shouldReceive('connection')->andThrow(new RuntimeException('connection refused'));

    $this->instance(CheckSystemHealth::class, healthAction(database: $database));

    $response = $this->getJson('/api/health');

    $response->assertStatus(503)->assertJsonPath('status', 'degraded');
    expect(degradedChecks($response))->toBe(['database']);
});

it('answers 503 when Redis is unreachable', function (): void {
    $redis = Mockery::mock(RedisFactory::class);
    $redis->shouldReceive('connection')->andThrow(new RuntimeException('connection refused'));

    $this->instance(CheckSystemHealth::class, healthAction(redis: $redis));

    $response = $this->getJson('/api/health');

    $response->assertStatus(503)->assertJsonPath('status', 'degraded');
    expect(degradedChecks($response))->toBe(['redis']);
});

it('answers 503 when the queue backend is unreachable', function (): void {
    $queue = Mockery::mock(QueueFactory::class);
    $queue->shouldReceive('connection')->andThrow(new RuntimeException('no connection to redis'));

    $this->instance(CheckSystemHealth::class, healthAction(queue: $queue));

    $response = $this->getJson('/api/health');

    $response->assertStatus(503)->assertJsonPath('status', 'degraded');
    expect(degradedChecks($response))->toBe(['queue']);
});

it('answers 503 when a migration is pending', function (): void {
    $this->instance(CheckSystemHealth::class, healthAction(
        migrator: migratedSchema(
            defined: ['2026_08_27_000000_create_widgets_table' => '/app/database/migrations/x.php'],
            ran: [],
        ),
    ));

    $response = $this->getJson('/api/health');

    $response->assertStatus(503)->assertJsonPath('status', 'degraded');
    expect(degradedChecks($response))->toBe(['migrations']);
});

it('hides the failure reason when debug output is off', function (): void {
    config(['app.debug' => false]);

    $redis = Mockery::mock(RedisFactory::class);
    $redis->shouldReceive('connection')->andThrow(new RuntimeException('AUTH failed for redis://cache:6379'));

    $this->instance(CheckSystemHealth::class, healthAction(redis: $redis));

    $response = $this->getJson('/api/health');

    $response->assertStatus(503);
    expect($response->json('checks.1'))->not->toHaveKey('detail')
        ->and($response->getContent())->not->toContain('AUTH failed');
});

it('includes the failure reason when debug output is on', function (): void {
    config(['app.debug' => true]);

    $redis = Mockery::mock(RedisFactory::class);
    $redis->shouldReceive('connection')->andThrow(new RuntimeException('connection refused'));

    $this->instance(CheckSystemHealth::class, healthAction(redis: $redis));

    $this->getJson('/api/health')
        ->assertStatus(503)
        ->assertJsonPath('checks.1.detail', 'connection refused');
});
