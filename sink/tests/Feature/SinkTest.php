<?php

declare(strict_types=1);

/**
 * The delivery payload is irrelevant to the sink; it is here only because a
 * real delivery carries one, and one test below proves the sink ignores it.
 */
function delivery(array $payload = ['event' => 'benchmark']): array
{
    return $payload;
}

it('answers a plain delivery with 200', function (): void {
    $this->postJson('/sink', delivery())->assertOk()->assertNoContent(200);
});

it('answers with 500 when every request is configured to fail', function (): void {
    $this->postJson('/sink?fail_rate=1', delivery())->assertStatus(500);
});

it('answers a scheduled failure with the configured status', function (): void {
    $this->postJson('/sink?fail_rate=1&status=503', delivery())->assertStatus(503);
});

it('answers a scheduled failure with a terminal status when asked for one', function (): void {
    // 404 is what makes a delivery terminal rather than retryable in PostBox's
    // retry policy — a benchmark that never sees one never exercises the split.
    $this->postJson('/sink?fail_rate=1&status=404', delivery())->assertStatus(404);
});

it('fails exactly the configured requests in sequence', function (): void {
    $statuses = [];

    for ($request = 0; $request < 10; $request++) {
        $statuses[] = $this->postJson('/sink?fail_rate=0.3', delivery())->getStatusCode();
    }

    // Three failures in ten, at the positions FailureScheduleTest pins.
    expect($statuses)->toBe([500, 200, 200, 200, 500, 200, 200, 500, 200, 200]);
});

it('reports its sequence position on every response', function (): void {
    for ($request = 0; $request < 3; $request++) {
        $this->postJson('/sink', delivery())
            ->assertHeader('Sink-Sequence', (string) $request);
    }
});

it('waits for the configured delay before answering', function (): void {
    $startedAt = hrtime(true);

    $this->postJson('/sink?delay=50', delivery())->assertOk();

    $elapsedMs = (hrtime(true) - $startedAt) / 1_000_000;

    expect($elapsedMs)->toBeGreaterThanOrEqual(50.0);
});

it('answers immediately when no delay is configured', function (): void {
    $startedAt = hrtime(true);

    $this->postJson('/sink', delivery())->assertOk();

    expect((hrtime(true) - $startedAt) / 1_000_000)->toBeLessThan(500.0);
});

it('cannot be configured by the delivery payload', function (): void {
    // A benchmark delivers tenant payloads the sink does not control. If the
    // body could reach the configuration, a run would silently measure
    // something other than what its script asked for.
    $this->postJson('/sink', ['status' => 503, 'fail_rate' => 1, 'delay' => 5000])
        ->assertOk();
});

it('refuses a configuration outside its range', function (string $query): void {
    $this->postJson("/sink?{$query}", delivery())->assertStatus(422);
})->with([
    'fail rate above one' => 'fail_rate=2',
    'negative fail rate' => 'fail_rate=-0.1',
    'negative delay' => 'delay=-1',
    'delay beyond the ceiling' => 'delay=30001',
    'a success status as a failure status' => 'status=200',
    'a status that is not a status' => 'status=99',
    'a non-numeric fail rate' => 'fail_rate=sometimes',
]);

it('reports healthy', function (): void {
    $this->get('/health')->assertNoContent();
});
