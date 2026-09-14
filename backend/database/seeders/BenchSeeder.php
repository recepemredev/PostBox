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
 * The target URL is a parameter, not the sink. This class knows nothing about
 * what is listening on the other end, which is what keeps modules.md's
 * dependency pointing one way.
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

        /** @var array{application: string, endpoint: string, token: string} $seeded */
        $seeded = app(TenantContext::class)->runFor(
            $tenant,
            fn (): array => $this->seed($membership->user),
        );

        $this->report($tenant, $seeded);
    }

    /**
     * @return array{application: string, endpoint: string, token: string}
     */
    private function seed(User $creator): array
    {
        $application = Application::factory()->create(['name' => 'Benchmark']);
        $eventType = EventType::factory()->create(['name' => self::EventTypeName]);

        $endpoint = Endpoint::factory()->create([
            'application_id' => $application->id,
            'url' => self::target(),
        ]);

        // Without an active secret AttemptDelivery refuses before it sends, and
        // the run would measure the refusal path rather than the delivery path.
        EndpointSecret::factory()->for($endpoint)->create();

        EndpointSubscription::factory()->create([
            'endpoint_id' => $endpoint->id,
            'event_type_id' => $eventType->id,
        ]);

        return [
            'application' => $application->public_id,
            'endpoint' => $endpoint->public_id,
            'token' => app(IssueApiKey::class)->handle('benchmark', $creator)->token,
        ];
    }

    private static function target(): string
    {
        // Config::string()'s own default parameter only applies when the key
        // is entirely absent, and config/bench.php always declares it — unset
        // in .env, its value is present but null, so the check has to be
        // manual rather than delegated to the helper's default.
        $configured = Config::get('bench.target_url');

        return is_string($configured) && $configured !== '' ? $configured : self::DefaultTarget;
    }

    /**
     * @param  array{application: string, endpoint: string, token: string}  $seeded
     */
    private function report(Tenant $tenant, array $seeded): void
    {
        $this->command?->table(['', ''], [
            ['tenant', $tenant->public_id],
            ['plan', $tenant->plan->value],
            ['application', $seeded['application']],
            ['endpoint', $seeded['endpoint']],
            ['target', self::target()],
            ['event type', self::EventTypeName],
            ['API key', $seeded['token']],
        ]);

        // The key is shown once here for the same reason the dashboard shows it
        // once: nothing writes the plaintext down. A run that loses it seeds
        // again — BenchSeeder is idempotent in effect if not in row identity,
        // since every benchmark run wants a fresh tenant regardless.
        $this->command?->warn('The API key above is not recoverable. Pass it to k6 as API_KEY.');
    }
}
