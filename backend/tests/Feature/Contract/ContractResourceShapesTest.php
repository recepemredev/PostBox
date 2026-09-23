<?php

declare(strict_types=1);

/*
 * Shapes Scramble's own static analysis cannot follow on its own, all recorded in
 * App\Support\Contract: HealthController returns a JsonResponse built and then
 * mutated (->response()->setStatusCode()), not a bare Resource; ReplayResource
 * spreads next_cursor in conditionally (D83); PublishMessageRequest's `payload`
 * rule is a plain `array`, which Scramble has no PHP type to infer an item shape
 * from and defaults to "array of strings" (DescribeIngestPayload).
 */

it('describes the same health report shape on both 200 and 503', function (): void {
    $operation = operationFor(generatedContract(), 'health');

    $schema200 = $operation['responses']['200']['content']['application/json']['schema'];
    $schema503 = $operation['responses']['503']['content']['application/json']['schema'];

    expect($schema200)->toBe($schema503)
        ->and($schema200['required'])->toEqualCanonicalizing(['status', 'checked_at', 'duration_ms', 'checks'])
        ->and($schema200['properties']['status']['enum'])->toEqualCanonicalizing(['ok', 'degraded']);

    // detail only ever appears when app.debug is true, which production never is —
    // documenting it would describe a field a real deployment never returns.
    expect($schema200['properties']['checks']['items']['properties'] ?? [])->not->toHaveKey('detail');
});

it('describes next_cursor on the shared replay receipt shape', function (): void {
    $document = generatedContract();

    foreach (['replays.message', 'replays.range'] as $routeName) {
        $raw = operationFor($document, $routeName)['responses']['201']['content']['application/json']['schema'];
        $schema = resolveSchema($document, $raw);

        expect($schema['properties'])->toHaveKeys([
            'id', 'message_id', 'endpoint_id', 'range_from', 'range_to', 'delivery_count', 'created_at', 'next_cursor',
        ]);

        // Only the receipt's own id is a required, always-present string; next_cursor
        // is documented but never required — a message-scope replay never returns it.
        expect($schema['required'] ?? [])->not->toContain('next_cursor');
    }
});

it('documents the ingest payload as a JSON object or a non-empty array, not an array of strings', function (): void {
    $document = generatedContract();

    $requestSchema = resolveSchema(
        $document,
        operationFor($document, 'messages.store')['requestBody']['content']['application/json']['schema'],
    );

    $payload = $requestSchema['properties']['payload'];

    expect($payload['anyOf'])->toHaveCount(2);

    $shapes = collect($payload['anyOf'])->keyBy('type');

    expect($shapes['object'])->not->toHaveKey('items')
        ->and($shapes['array']['minItems'])->toBe(1);
});
