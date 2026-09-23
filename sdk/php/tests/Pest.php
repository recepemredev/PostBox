<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PostBox\PostBox;
use PostBox\RetryPolicy;
use Tests\Support\FakeClient;

/**
 * @param  array<string, mixed>  $body
 * @param  array<string, string>  $headers
 */
function jsonResponse(int $status, array $body, array $headers = []): Response
{
    $factory = new Psr17Factory;

    $response = $factory->createResponse($status)
        ->withHeader('Content-Type', 'application/json')
        ->withBody($factory->createStream(json_encode($body, JSON_THROW_ON_ERROR)));

    foreach ($headers as $name => $value) {
        $response = $response->withHeader($name, $value);
    }

    return $response;
}

/**
 * @param  (callable(int): void)|null  $sleep
 */
function postBox(FakeClient $client, ?RetryPolicy $retryPolicy = null, ?callable $sleep = null): PostBox
{
    $factory = new Psr17Factory;

    return new PostBox(
        apiKey: 'pbk_test_key',
        baseUrl: 'https://api.postbox.test',
        httpClient: $client,
        requestFactory: $factory,
        streamFactory: $factory,
        retryPolicy: $retryPolicy ?? new RetryPolicy,
        sleep: $sleep,
    );
}

/**
 * Reads the shared Step 16 conformance vectors this package holds itself to,
 * the same file the backend and sdk/ts also run.
 *
 * @return array<string, mixed>
 */
function signatureVectors(): array
{
    return json_decode(
        file_get_contents(dirname(__DIR__, 3).'/contract/signature-vectors.json') ?: '{}',
        associative: true,
        flags: JSON_THROW_ON_ERROR,
    );
}

/**
 * Each vector is a string-keyed array, and Pest's own ->with() splats a case
 * value that is itself an array as named arguments rather than passing it
 * through as one value. Wrapping each row in a one-element list keeps it a
 * single positional argument instead.
 *
 * @param  list<array<string, mixed>>  $vectors
 * @return array<string, array{0: array<string, mixed>}>
 */
function asDataset(array $vectors): array
{
    $dataset = [];

    foreach ($vectors as $vector) {
        $dataset[(string) $vector['name']] = [$vector];
    }

    return $dataset;
}
