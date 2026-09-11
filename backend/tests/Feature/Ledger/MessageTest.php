<?php

declare(strict_types=1);

use App\Models\Application;
use App\Models\EventType;
use App\Models\Message;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/*
 * The message log's guarantees are the partitioned table's guarantees: a row
 * lands in the partition its created_at falls in, an oversized payload never
 * reaches storage, and Row Level Security holds even though the physical table
 * behind "messages" changes every month.
 */

beforeEach(function (): void {
    $this->acme = tenantNamed('Acme');
    $this->globex = tenantNamed('Globex');
});

it('routes a message into the partition for the month it was created', function (): void {
    $message = forTenant($this->acme, fn (): Message => Message::factory()->create());

    $row = forTenant($this->acme, fn (): object => DB::table('messages')
        ->where('id', $message->id)
        ->selectRaw('tableoid::regclass::text as partition')
        ->first());

    expect($row->partition)->toBe('messages_'.now()->format('Y_m'));
});

it('refuses a payload larger than the size cap', function (): void {
    /*
     * Past the ceiling this table stores, which is twice the one the ingest
     * endpoint advertises — the two measure different strings, so they are
     * deliberately different numbers. Deriving the figure here rather than
     * naming one keeps the test from quietly passing for the wrong reason the
     * next time either ceiling moves.
     */
    $oversized = str_repeat('a', Config::integer('postbox.ingest.max_payload_bytes') * 2 + 1);

    expect(fn () => forTenant($this->acme, fn (): Message => Message::factory()->create([
        'payload' => ['blob' => $oversized],
    ])))->toThrow(QueryException::class);
});

it('hides another tenant even though every row lives in the same shared partition', function (): void {
    forTenant($this->acme, fn (): Message => Message::factory()->create());
    forTenant($this->globex, fn (): Message => Message::factory()->create());

    $acmeCount = forTenant($this->acme, fn (): int => Message::query()->count());
    $globexCount = forTenant($this->globex, fn (): int => Message::query()->count());

    expect($acmeCount)->toBe(1)
        ->and($globexCount)->toBe(1);
});

it('refuses a message written for another tenant, straight through the query builder', function (): void {
    [$applicationId, $eventTypeId] = forTenant($this->acme, fn (): array => [
        Application::factory()->create()->id,
        EventType::factory()->create()->id,
    ]);

    $insert = fn (): bool => DB::table('messages')->insert([
        'public_id' => 'msg_forged',
        'tenant_id' => $this->globex->id,
        'application_id' => $applicationId,
        'event_type_id' => $eventTypeId,
        'payload' => json_encode(['ok' => true], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => forTenant($this->acme, $insert))->toThrow(QueryException::class);
});
