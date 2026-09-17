<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Identity\IssueApiKey;
use App\Actions\Tenancy\CreateTenant;
use App\Enums\Plan;
use App\Models\Application;
use App\Models\Endpoint;
use App\Models\EndpointSecret;
use App\Models\EndpointSubscription;
use App\Models\EventType;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

/**
 * The tenant a benchmark run publishes into.
 *
 * A seeder rather than a console command, and the distinction is the module
 * boundary in modules.md: Bench depends on Delivery, Delivery never depends on
 * Bench. A `postbox:bench-seed` command would put the benchmark inside the
 * application's own surface — something an operator could run in production —
 * and would make Delivery's own console namespace carry a Bench concern.
 * Seeders are already development tooling, are never on a request path, and
 * DatabaseSeeder does not call this one: it runs only when named explicitly
 * (`db:seed --class=BenchSeeder`), never as part of an ordinary fresh install.
 *
 * The tenant is provisioned on the pro plan directly — CreateTenant does not
 * take a plan, and giving it one for a single caller that needs it would put
 * a benchmark concern in an action every other caller shares. Governor's pro
 * limits are themselves overridden for the bench profile
 * (config/postbox.php, compose.bench.yaml), so plan alone is not what makes
 * the run possible; it is what makes the *right* limits apply.
 *
 * The target URL (or URLs) is a parameter, not the sink. This class knows
 * nothing about what is listening on the other end, which is what keeps
 * modules.md's dependency pointing one way. One publish fans out to every
 * seeded endpoint alike — several target URLs are several subscribers to the
 * same event type, exactly what an operator's own dashboard would produce,
 * not a second delivery path built for this class alone.
 *
 * Run:
 *   docker compose -f compose.yaml -f compose.bench.yaml exec -T backend \
 *     php artisan db:seed --class=BenchSeeder --force
 */
final class BenchSeeder extends Seeder
{
    private const string DefaultTarget = 'http://sink:8000/sink?delay=0&fail_rate=0';

    private const string EventTypeName = 'bench.event';

    public function run(CreateTenant $createTenant): void
    {
        $membership = $createTenant->handle(
            'Benchmark '.Str::lower(Str::random(6)),
            'Benchmark Operator',
            Str::lower(Str::random(10)).'@benchmark.invalid',
            Str::password(24),
        );

        $tenant = $membership->tenant;
        $tenant->update(['plan' => Plan::Pro]);

        /** @var array{application: string, endpoints: list<array{url: string, endpoint: string}>, token: string} $seeded */
        $seeded = app(TenantContext::class)->runFor(
            $tenant,
            fn (): array => $this->seed($membership->user),
        );

        $this->report($tenant, $seeded);
    }

    /**
     * @return array{application: string, endpoints: list<array{url: string, endpoint: string}>, token: string}
     */
    private function seed(User $creator): array
    {
        $application = Application::factory()->create(['name' => 'Benchmark']);
        $eventType = EventType::factory()->create(['name' => self::EventTypeName]);

        $endpoints = array_map(
            fn (string $url): array => $this->seedEndpoint($application, $eventType, $url),
            self::targets(),
        );

        return [
            'application' => $application->public_id,
            'endpoints' => $endpoints,
            'token' => app(IssueApiKey::class)->handle('benchmark', $creator)->token,
        ];
    }

    /**
     * @return array{url: string, endpoint: string}
     */
    private function seedEndpoint(Application $application, EventType $eventType, string $url): array
    {
        $endpoint = Endpoint::factory()->create([
            'application_id' => $application->id,
            'url' => $url,
        ]);

        // Without an active secret AttemptDelivery refuses before it sends, and
        // the run would measure the refusal path rather than the delivery path.
        EndpointSecret::factory()->for($endpoint)->create();

        EndpointSubscription::factory()->create([
            'endpoint_id' => $endpoint->id,
            'event_type_id' => $eventType->id,
        ]);

        return ['url' => $url, 'endpoint' => $endpoint->public_id];
    }

    /**
     * @return list<string>
     */
    private static function targets(): array
    {
        // Config::string()'s own default parameter only applies when the key
        // is entirely absent, and config/bench.php always declares it — unset
        // in .env, its value is present but null, so the check has to be
        // manual rather than delegated to the helper's default.
        $configured = Config::get('bench.target_urls');

        if (! is_string($configured) || $configured === '') {
            return [self::DefaultTarget];
        }

        return array_values(array_filter(array_map('trim', explode(',', $configured)), fn (string $url): bool => $url !== ''));
    }

    /**
     * @param  array{application: string, endpoints: list<array{url: string, endpoint: string}>, token: string}  $seeded
     */
    private function report(Tenant $tenant, array $seeded): void
    {
        $rows = [
            ['tenant', $tenant->public_id],
            ['plan', $tenant->plan->value],
            ['application', $seeded['application']],
            ['event type', self::EventTypeName],
        ];

        foreach ($seeded['endpoints'] as $i => $endpoint) {
            $rows[] = ['endpoint '.($i + 1), $endpoint['endpoint']];
            $rows[] = ['target '.($i + 1), $endpoint['url']];
        }

        $rows[] = ['API key', $seeded['token']];

        $this->command?->table(['', ''], $rows);

        // The key is shown once here for the same reason the dashboard shows it
        // once: nothing writes the plaintext down. A run that loses it seeds
        // again — BenchSeeder is idempotent in effect if not in row identity,
        // since every benchmark run wants a fresh tenant regardless.
        $this->command?->warn('The API key above is not recoverable. Pass it to k6 as API_KEY.');
    }
}
