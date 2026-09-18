<?php

declare(strict_types=1);

/*
 * D26: an ingest producer carries a bearer API key, a dashboard operator carries a
 * session cookie, and never both on the same route. These tests are the sabotaged-
 * once-and-reverted kind (conventions.md): each was run once against a route that
 * had the wrong scheme, confirmed to fail for that reason, then reverted.
 */

it('declares exactly the two credentials this API accepts', function (): void {
    $document = generatedContract();

    expect(array_keys($document['components']['securitySchemes']))
        ->toEqualCanonicalizing(['bearerApiKey', 'sessionCookie']);

    expect($document['components']['securitySchemes']['bearerApiKey'])
        ->toMatchArray(['type' => 'http', 'scheme' => 'bearer']);

    expect($document['components']['securitySchemes']['sessionCookie'])
        ->toMatchArray(['type' => 'apiKey', 'in' => 'cookie']);
});

it('requires the bearer API key on the ingest route, and nothing else', function (): void {
    $document = generatedContract();

    expect(operationFor($document, 'messages.store')['security'])
        ->toBe([['bearerApiKey' => []]]);
});

it('requires the session cookie on every operator route', function (): void {
    $document = generatedContract();

    foreach (['logout', 'me', 'api-keys.index', 'api-keys.store', 'api-keys.destroy', 'replays.message', 'replays.range'] as $routeName) {
        expect(operationFor($document, $routeName)['security'])
            ->toBe([['sessionCookie' => []]], "route [{$routeName}]");
    }
});

it('requires no credential on the truly public routes', function (): void {
    $document = generatedContract();

    foreach (['health', 'csrf-cookie', 'login'] as $routeName) {
        expect(operationFor($document, $routeName)['security'])
            ->toBe([], "route [{$routeName}]");
    }
});
