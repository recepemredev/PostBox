<?php

declare(strict_types=1);

use App\Models\Delivery;
use App\Models\Endpoint;
use App\Models\Message;
use App\Models\Replay;
use Illuminate\Database\QueryException;

/*
 * The two constraints this step's schema adds: what shape a replay request is
 * allowed to take (replays_scope_shape_check, replays_range_order_check), and
 * that only one *original* delivery may ever exist for a message and an
 * endpoint (deliveries_original_fanout_unique) — a replay is free to share
 * that pair, because it is a second obligation, never a second original.
 */

beforeEach(function (): void {
    $this->tenant = tenantNamed('Acme');
    $this->message = forTenant($this->tenant, fn (): Message => Message::factory()->create());
    $this->endpoint = forTenant($this->tenant, fn (): Endpoint => Endpoint::factory()->create());
});

it('accepts a replay scoped to a single message', function (): void {
    $replay = forTenant(
        $this->tenant,
        fn (): Replay => Replay::factory()->create(['message_id' => $this->message->id]),
    );

    expect($replay->message_id)->toBe($this->message->id)
        ->and($replay->endpoint_id)->toBeNull();
});

it('accepts a replay scoped to a message and one of its endpoints', function (): void {
    $replay = forTenant($this->tenant, fn (): Replay => Replay::factory()->create([
        'message_id' => $this->message->id,
        'endpoint_id' => $this->endpoint->id,
    ]));

    expect($replay->message_id)->toBe($this->message->id)
        ->and($replay->endpoint_id)->toBe($this->endpoint->id);
});

it('accepts a replay scoped to an endpoint over a bounded range', function (): void {
    $replay = forTenant($this->tenant, fn (): Replay => Replay::factory()->create([
        'message_id' => null,
        'endpoint_id' => $this->endpoint->id,
        'range_from' => now()->subDay(),
        'range_to' => now(),
    ]));

    expect($replay->message_id)->toBeNull()
        ->and($replay->endpoint_id)->toBe($this->endpoint->id);
});

it('refuses a replay that names neither a message nor a bounded endpoint range', function (): void {
    forTenant($this->tenant, fn () => Replay::factory()->create(['message_id' => null]));
})->throws(QueryException::class);

it('refuses a replay that names both a message and a range', function (): void {
    forTenant($this->tenant, fn () => Replay::factory()->create([
        'message_id' => $this->message->id,
        'endpoint_id' => $this->endpoint->id,
        'range_from' => now()->subDay(),
        'range_to' => now(),
    ]));
})->throws(QueryException::class);

it('refuses an endpoint range with only one bound', function (): void {
    forTenant($this->tenant, fn () => Replay::factory()->create([
        'message_id' => null,
        'endpoint_id' => $this->endpoint->id,
        'range_from' => now()->subDay(),
        'range_to' => null,
    ]));
})->throws(QueryException::class);

it('refuses a range whose end does not come after its start', function (): void {
    $now = now();

    forTenant($this->tenant, fn () => Replay::factory()->create([
        'message_id' => null,
        'endpoint_id' => $this->endpoint->id,
        'range_from' => $now,
        'range_to' => $now,
    ]));
})->throws(QueryException::class);

it('refuses an idempotency key recorded without its request hash', function (): void {
    forTenant($this->tenant, fn () => Replay::factory()->create([
        'message_id' => $this->message->id,
        'idempotency_key' => 'recover-1',
    ]));
})->throws(QueryException::class);

it('refuses a request hash recorded without an idempotency key', function (): void {
    forTenant($this->tenant, fn () => Replay::factory()->create([
        'message_id' => $this->message->id,
        'request_hash' => str_repeat('a', 64),
    ]));
})->throws(QueryException::class);

it('lets an operator reserve the same idempotency key only once per tenant', function (): void {
    forTenant($this->tenant, fn () => Replay::factory()->create([
        'message_id' => $this->message->id,
        'idempotency_key' => 'recover-1',
        'request_hash' => str_repeat('a', 64),
    ]));

    expect(fn () => forTenant($this->tenant, fn () => Replay::factory()->create([
        'message_id' => $this->message->id,
        'idempotency_key' => 'recover-1',
        'request_hash' => str_repeat('a', 64),
    ])))->toThrow(QueryException::class);
});

it('lets two tenants reuse the same idempotency key independently', function (): void {
    $other = tenantNamed('Globex');

    $acme = forTenant($this->tenant, fn (): Replay => Replay::factory()->create([
        'message_id' => $this->message->id,
        'idempotency_key' => 'shared-key',
        'request_hash' => str_repeat('a', 64),
    ]));

    $globex = forTenant($other, function (): Replay {
        $message = Message::factory()->create();

        return Replay::factory()->create([
            'message_id' => $message->id,
            'idempotency_key' => 'shared-key',
            'request_hash' => str_repeat('a', 64),
        ]);
    });

    expect($acme->tenant_id)->toBe($this->tenant->id)
        ->and($globex->tenant_id)->toBe($other->id);
});

it('lets several replays of the same message go without a key at all', function (): void {
    forTenant($this->tenant, function (): void {
        Replay::factory()->count(3)->create(['message_id' => $this->message->id]);
    });

    expect(forTenant($this->tenant, fn (): int => Replay::query()->count()))->toBe(3);
});

it("keeps two tenants from reading each other's replays", function (): void {
    forTenant($this->tenant, fn () => Replay::factory()->create(['message_id' => $this->message->id]));

    $other = tenantNamed('Globex');

    expect(forTenant($other, fn (): int => Replay::query()->count()))->toBe(0);
});

it('refuses a second original delivery for the same message and endpoint', function (): void {
    forTenant($this->tenant, fn () => Delivery::factory()->create([
        'message_id' => $this->message->id,
        'endpoint_id' => $this->endpoint->id,
    ]));

    expect(fn () => forTenant($this->tenant, fn () => Delivery::factory()->create([
        'message_id' => $this->message->id,
        'endpoint_id' => $this->endpoint->id,
    ])))->toThrow(QueryException::class);
});

it('lets a replay share its pair with the original it replays', function (): void {
    forTenant($this->tenant, function (): void {
        Delivery::factory()->create(['message_id' => $this->message->id, 'endpoint_id' => $this->endpoint->id]);

        $replay = Replay::factory()->create([
            'message_id' => $this->message->id,
            'endpoint_id' => $this->endpoint->id,
        ]);

        Delivery::factory()->create([
            'message_id' => $this->message->id,
            'endpoint_id' => $this->endpoint->id,
            'replay_id' => $replay->id,
        ]);
    });

    $count = forTenant(
        $this->tenant,
        fn (): int => Delivery::query()->where('message_id', $this->message->id)->count(),
    );

    expect($count)->toBe(2);
});

it('lets the same pair be replayed more than once', function (): void {
    forTenant($this->tenant, function (): void {
        Delivery::factory()->create(['message_id' => $this->message->id, 'endpoint_id' => $this->endpoint->id]);

        foreach (range(1, 2) as $ignored) {
            $replay = Replay::factory()->create([
                'message_id' => $this->message->id,
                'endpoint_id' => $this->endpoint->id,
            ]);

            Delivery::factory()->create([
                'message_id' => $this->message->id,
                'endpoint_id' => $this->endpoint->id,
                'replay_id' => $replay->id,
            ]);
        }
    });

    $count = forTenant(
        $this->tenant,
        fn (): int => Delivery::query()->where('message_id', $this->message->id)->count(),
    );

    expect($count)->toBe(3);
});

it("reaches a delivery's replay and a replay's deliveries through the relation", function (): void {
    forTenant($this->tenant, function (): void {
        Delivery::factory()->create(['message_id' => $this->message->id, 'endpoint_id' => $this->endpoint->id]);

        $replay = Replay::factory()->create([
            'message_id' => $this->message->id,
            'endpoint_id' => $this->endpoint->id,
        ]);

        $delivery = Delivery::factory()->create([
            'message_id' => $this->message->id,
            'endpoint_id' => $this->endpoint->id,
            'replay_id' => $replay->id,
        ]);

        expect($delivery->replay?->is($replay))->toBeTrue()
            ->and($replay->deliveries->pluck('id')->all())->toBe([$delivery->id]);
    });
});
