<?php

declare(strict_types=1);

use App\Models\DeliveryAttempt;
use App\Support\Delivery\TransportResult;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/*
 * "Workers stay stateless... under docker compose up --scale worker=N"
 * (conventions.md) is a claim about two different failure modes, and this
 * file covers both. The first two tests prove it end to end: two tenants
 * attempted back to back in one process — exactly what one long-lived
 * Horizon worker does across many jobs, never a fresh container per job —
 * never cross-contaminate, and TenantContext never stays bound to whoever
 * ran last. The third proves it structurally: no class under app/ declares a
 * static property at all, which is what would let state survive from one job
 * to the next inside a single worker process regardless of what
 * TenantContext itself does.
 */

it('attempts deliveries for two tenants in one process without leaking the first into the second', function (): void {
    [$acme, , $acmeDelivery] = publishedDelivery('Acme');
    [$globex, , $globexDelivery] = publishedDelivery('Globex');

    fakeTransport(TransportResult::responded(200, [], 'ok', 5), times: 2);

    // Two full job invocations, back to back, in this one PHP process — the
    // same shape a persistent Horizon worker takes across an ordinary shift,
    // not two separate test processes standing in for two workers.
    attemptDelivery($acme, $acmeDelivery);
    attemptDelivery($globex, $globexDelivery);

    expect(freshDelivery($acme, $acmeDelivery)->status->value)->toBe('succeeded')
        ->and(freshDelivery($globex, $globexDelivery)->status->value)->toBe('succeeded')
        ->and(forTenant($acme, fn (): int => DeliveryAttempt::query()->count()))->toBe(1)
        ->and(forTenant($globex, fn (): int => DeliveryAttempt::query()->count()))->toBe(1);
});

it('leaves no tenant current once a job has finished', function (): void {
    [$tenant, , $delivery] = publishedDelivery();
    fakeTransport(TransportResult::responded(200, [], 'ok', 5));

    // publishedDelivery() itself publishes through a real HTTP request, which
    // leaves a tenant bound the way an actual request does — a worker never
    // makes one, so that residue is cleared first. Otherwise this would be
    // asserting what arranging the fixture left behind, not what the job
    // itself does.
    app(TenantContext::class)->forget();

    attemptDelivery($tenant, $delivery);

    expect(app(TenantContext::class)->has())->toBeFalse();
});

it('declares no static mutable state anywhere in the application', function (): void {
    $offenders = [];

    foreach (File::allFiles(app_path()) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $class = 'App\\'.Str::of($file->getRelativePathname())
            ->beforeLast('.php')
            ->replace(DIRECTORY_SEPARATOR, '\\');

        if (! class_exists($class) && ! trait_exists($class) && ! enum_exists($class) && ! interface_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);

        foreach ($reflection->getProperties(ReflectionProperty::IS_STATIC) as $property) {
            // Inherited from something outside app/ — a framework base
            // class's own static property is not this codebase's to answer
            // for, and reflecting a subclass reports it too.
            if ($property->getDeclaringClass()->getName() !== $class) {
                continue;
            }

            $offenders[] = "{$class}::\${$property->getName()}";
        }
    }

    // A class-level constant is not a property at all from Reflection's own
    // point of view, so it never reaches this list — the rule this enforces
    // is narrower than "no static keyword anywhere" and exactly as wide as
    // "nothing a worker could accidentally carry from one job into the next".
    expect($offenders)->toBe([]);
});
