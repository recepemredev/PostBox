<?php

declare(strict_types=1);

namespace App\Actions\Health;

use App\Support\Health\CheckResult;
use App\Support\Health\HealthReport;
use App\Support\Health\HealthStatus;
use Closure;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migrator;
use RuntimeException;
use Throwable;

/**
 * Probes every dependency this instance needs in order to serve traffic.
 *
 * A health endpoint that only returns 200 proves nothing, so all four probes hit
 * the real dependency. Every collaborator is injected: it is the only way the
 * failure modes can be tested, and the container healthcheck runs the same code
 * as the HTTP endpoint.
 */
final readonly class CheckSystemHealth
{
    public function __construct(
        private DatabaseManager $database,
        private RedisFactory $redis,
        private QueueFactory $queue,
        private Migrator $migrator,
    ) {}

    public function handle(): HealthReport
    {
        $startedAt = hrtime(true);

        $checks = [
            $this->checkDatabase(),
            $this->checkRedis(),
            $this->checkQueue(),
            $this->checkMigrations(),
        ];

        return new HealthReport($checks, $this->elapsedMs($startedAt));
    }

    /**
     * Opens the connection rather than running a query: PDO connects lazily, so
     * asking for it is a genuine round trip without putting SQL in application code.
     */
    private function checkDatabase(): CheckResult
    {
        return $this->probe('database', function (): void {
            $this->database->connection()->getPdo();
        });
    }

    private function checkRedis(): CheckResult
    {
        return $this->probe('redis', function (): void {
            $this->redis->connection()->command('ping', []);
        });
    }

    /**
     * Reads the depth of the default queue. The number is not interpreted — a
     * backlog is an operational signal, not an unhealthy instance. Reaching the
     * backend at all is what is being asserted.
     */
    private function checkQueue(): CheckResult
    {
        return $this->probe('queue', function (): void {
            $this->queue->connection()->size();
        });
    }

    /**
     * An instance running against a schema it does not expect is worse than an
     * instance that is down, because it fails silently and per request.
     */
    private function checkMigrations(): CheckResult
    {
        return $this->probe('migrations', function (): void {
            if (! $this->migrator->repositoryExists()) {
                throw new RuntimeException('Migration repository does not exist.');
            }

            $pending = $this->pendingMigrationCount();

            if ($pending > 0) {
                throw new RuntimeException("{$pending} migration(s) pending.");
            }
        });
    }

    private function pendingMigrationCount(): int
    {
        $paths = array_merge($this->migrator->paths(), [database_path('migrations')]);
        $defined = array_keys($this->migrator->getMigrationFiles($paths));

        return count(array_diff($defined, $this->migrator->getRepository()->getRan()));
    }

    /**
     * Times one probe and translates any failure into a result. Every check goes
     * through here so timing and failure handling exist in exactly one place.
     *
     * @param  Closure(): void  $probe
     */
    private function probe(string $name, Closure $probe): CheckResult
    {
        $startedAt = hrtime(true);

        try {
            $probe();

            return new CheckResult($name, HealthStatus::Ok, $this->elapsedMs($startedAt));
        } catch (Throwable $failure) {
            return new CheckResult(
                $name,
                HealthStatus::Degraded,
                $this->elapsedMs($startedAt),
                $failure->getMessage(),
            );
        }
    }

    private function elapsedMs(int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }
}
