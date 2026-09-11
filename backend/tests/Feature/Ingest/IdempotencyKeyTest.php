<?php

declare(strict_types=1);

use App\Models\IdempotencyKey;
use App\Models\Message;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * The one guarantee this table exists to hold: a tenant can never reserve the
 * same idempotency key twice. What a replay does once it finds an existing
 * reservation belongs to Step 4's ingest action, not to this table.
 */

beforeEach(function (): void {
    $this->acme = tenantNamed('Acme');
    $this->globex = tenantNamed('Globex');
});

it('refuses a second reservation of the same key in the tenant', function (): void {
    $reserve = fn (): IdempotencyKey => IdempotencyKey::factory()->create(['key' => 'req-001']);

    forTenant($this->acme, $reserve);

    expect(fn () => forTenant($this->acme, $reserve))->toThrow(QueryException::class);
});

it('lets two tenants reserve the same key independently', function (): void {
    $acme = forTenant($this->acme, fn (): IdempotencyKey => IdempotencyKey::factory()->create(['key' => 'req-001']));
    $globex = forTenant($this->globex, fn (): IdempotencyKey => IdempotencyKey::factory()->create(['key' => 'req-001']));

    expect($acme->tenant_id)->toBe($this->acme->id)
        ->and($globex->tenant_id)->toBe($this->globex->id);
});

it('refuses a key that expires before it was reserved', function (): void {
    $messageId = forTenant($this->acme, fn (): int => Message::factory()->create()->id);

    $insert = fn (): bool => DB::table('idempotency_keys')->insert([
        'tenant_id' => $this->acme->id,
        'key' => 'req-002',
        'request_hash' => str_repeat('a', 64),
        'message_id' => $messageId,
        'created_at' => now(),
        'expires_at' => now()->subMinute(),
    ]);

    expect(fn () => forTenant($this->acme, $insert))->toThrow(QueryException::class);
});
