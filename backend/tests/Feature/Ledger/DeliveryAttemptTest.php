<?php

declare(strict_types=1);

use App\Enums\AttemptOutcome;
use App\Models\DeliveryAttempt;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * An attempt is the historical record of one HTTP try. Its shape enforces the
 * one invariant that actually matters: a response status exists exactly when a
 * response was received, and "succeeded" means exactly a 2xx one — not a second,
 * driftable fact recorded beside the outcome that already names it.
 */

beforeEach(function (): void {
    $this->tenant = tenantNamed('Acme');
});

it('constrains the outcome column to exactly the AttemptOutcome enum', function (): void {
    $constraint = DB::selectOne(
        "select pg_get_constraintdef(oid) as definition
         from pg_constraint where conname = 'delivery_attempts_outcome_check'"
    );

    preg_match_all("/'([^']+)'/", (string) $constraint->definition, $matches);

    $allowed = collect($matches[1])->sort()->values()->all();
    $declared = collect(AttemptOutcome::cases())
        ->map(fn (AttemptOutcome $case): string => $case->value)
        ->sort()
        ->values()
        ->all();

    expect($allowed)->toBe($declared);
});

it('routes an attempt into the partition for the month it was created', function (): void {
    $attempt = forTenant($this->tenant, fn (): DeliveryAttempt => DeliveryAttempt::factory()->create());

    $row = forTenant($this->tenant, fn (): object => DB::table('delivery_attempts')
        ->where('id', $attempt->id)
        ->selectRaw('tableoid::regclass::text as partition')
        ->first());

    expect($row->partition)->toBe('delivery_attempts_'.now()->format('Y_m'));
});

it('refuses a succeeded outcome recorded against a non-2xx status', function (): void {
    expect(fn () => forTenant($this->tenant, fn (): DeliveryAttempt => DeliveryAttempt::factory()->create([
        'outcome' => AttemptOutcome::Succeeded,
        'response_status' => 500,
    ])))->toThrow(QueryException::class);
});

it('refuses a network failure recorded with a response status', function (): void {
    expect(fn () => forTenant($this->tenant, fn (): DeliveryAttempt => DeliveryAttempt::factory()
        ->timedOut()
        ->create(['response_status' => 200])))
        ->toThrow(QueryException::class);
});

it('accepts a blocked outcome with no response status', function (): void {
    $attempt = forTenant($this->tenant, fn (): DeliveryAttempt => DeliveryAttempt::factory()->blocked()->create());

    expect($attempt->outcome)->toBe(AttemptOutcome::Blocked)
        ->and($attempt->response_status)->toBeNull();
});

it('refuses a blocked outcome recorded with a response status', function (): void {
    expect(fn () => forTenant($this->tenant, fn (): DeliveryAttempt => DeliveryAttempt::factory()
        ->blocked()
        ->create(['response_status' => 200])))
        ->toThrow(QueryException::class);
});

it('refuses a request body larger than the size cap', function (): void {
    $oversized = str_repeat('a', 300_000);

    expect(fn () => forTenant($this->tenant, fn (): DeliveryAttempt => DeliveryAttempt::factory()->create([
        'request_body' => $oversized,
    ])))->toThrow(QueryException::class);
});
