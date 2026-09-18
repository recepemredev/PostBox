<?php

declare(strict_types=1);

/*
 * The three `governor`-guarded write routes: ingest, message replay, range replay.
 * None of this is inferable from the controllers alone — EnforceLimits and the
 * idempotency reservation are middleware and a validation-time concern, not calls
 * the controller action makes, so Scramble's own static analysis cannot see any of
 * it. See App\Support\Contract\DescribeIdempotentWrites.
 */

$governedRoutes = ['messages.store', 'replays.message', 'replays.range'];

it('carries Idempotency-Key as a header parameter, never a body field', function (string $routeName): void {
    $operation = operationFor(generatedContract(), $routeName);

    $header = collect($operation['parameters'] ?? [])
        ->first(fn (array $parameter) => $parameter['name'] === 'Idempotency-Key');

    expect($header)->not->toBeNull()
        ->and($header['in'])->toBe('header')
        ->and($header['required'] ?? false)->toBeFalse();
})->with($governedRoutes);

it('answers both a 201 (new) and a 200 (idempotent replay) with the same body shape', function (string $routeName): void {
    $operation = operationFor(generatedContract(), $routeName);

    expect($operation['responses'])->toHaveKeys(['200', '201']);

    $bothSchemas = [
        $operation['responses']['200']['content']['application/json']['schema'] ?? null,
        $operation['responses']['201']['content']['application/json']['schema'] ?? null,
    ];

    expect($bothSchemas[0])->toBe($bothSchemas[1])
        ->and($operation['responses']['200']['headers'])->toHaveKey('Idempotent-Replay')
        ->and($operation['responses']['201']['headers'])->not->toHaveKey('Idempotent-Replay');
})->with($governedRoutes);

it('carries all six rate limit and quota headers on every 2xx response', function (string $routeName): void {
    $operation = operationFor(generatedContract(), $routeName);

    $expected = [
        'RateLimit-Limit', 'RateLimit-Remaining', 'RateLimit-Reset',
        'Quota-Limit', 'Quota-Remaining', 'Quota-Reset',
    ];

    foreach (['200', '201'] as $code) {
        expect(array_keys($operation['responses'][$code]['headers']))
            ->toEqualCanonicalizing([...$expected, ...($code === '200' ? ['Idempotent-Replay'] : [])]);
    }
})->with($governedRoutes);

it('describes the idempotency conflict, rate limit and quota exhaustion responses', function (string $routeName): void {
    $operation = operationFor(generatedContract(), $routeName);

    expect($operation['responses'])->toHaveKeys(['409', '429', '402']);
    expect($operation['responses']['429']['headers'])->toHaveKey('Retry-After');
})->with($governedRoutes);

it('describes the missing replay target only on the two replay routes', function (): void {
    $document = generatedContract();

    expect(operationFor($document, 'replays.message')['responses'])->toHaveKey('404');
    expect(operationFor($document, 'replays.range')['responses'])->toHaveKey('404');

    // messages.store has a 404 too (no such application), but nothing that is
    // ReplayTargetNotFound's own wording — this only proves the response exists,
    // ContractCoverageTest's drift check is what pins its exact shape.
    expect(operationFor($document, 'messages.store')['responses'])->toHaveKey('404');
});
