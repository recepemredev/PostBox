<?php

declare(strict_types=1);

use App\Enums\DeliveryStatus;
use App\Models\Delivery;
use App\Models\Endpoint;
use App\Models\Message;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * A delivery is what the fan-out opens for one message and one endpoint, and it
 * must open at most one. The status column answers a single question — done or
 * not — and the CHECK behind it is held to the enum the same way every status
 * column in this schema is.
 */

beforeEach(function (): void {
    $this->tenant = tenantNamed('Acme');
});

it('constrains the status column to exactly the DeliveryStatus enum', function (): void {
    $constraint = DB::selectOne(
        "select pg_get_constraintdef(oid) as definition
         from pg_constraint where conname = 'deliveries_status_check'"
    );

    preg_match_all("/'([^']+)'/", (string) $constraint->definition, $matches);

    $allowed = collect($matches[1])->sort()->values()->all();
    $declared = collect(DeliveryStatus::cases())
        ->map(fn (DeliveryStatus $case): string => $case->value)
        ->sort()
        ->values()
        ->all();

    expect($allowed)->toBe($declared);
});

it('refuses a second delivery for the same message and endpoint', function (): void {
    [$message, $endpoint] = forTenant($this->tenant, fn (): array => [
        Message::factory()->create(),
        Endpoint::factory()->create(),
    ]);

    $open = fn (): Delivery => Delivery::factory()->create([
        'message_id' => $message->id,
        'endpoint_id' => $endpoint->id,
    ]);

    forTenant($this->tenant, $open);

    expect(fn () => forTenant($this->tenant, $open))->toThrow(QueryException::class);
});
