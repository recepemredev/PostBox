<?php

declare(strict_types=1);

use App\Actions\Health\CheckSystemHealth;
use Illuminate\Contracts\Redis\Factory as RedisFactory;

/*
 * The command is what every PHP container reports its health with, so its exit code
 * is the contract — not its output.
 */

it('exits zero when every dependency is healthy', function (): void {
    $this->artisan('postbox:health')->assertExitCode(0);
});

it('exits non-zero when a dependency is unreachable', function (): void {
    $redis = Mockery::mock(RedisFactory::class);
    $redis->shouldReceive('connection')->andThrow(new RuntimeException('connection refused'));

    $this->instance(CheckSystemHealth::class, healthAction(redis: $redis));

    $this->artisan('postbox:health')->assertExitCode(1);
});
