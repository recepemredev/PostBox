<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
 * The one guarantee this whole step exists for: an endpoint added to routes/api.php
 * without the contract being regenerated fails here, not silently in a reviewer's
 * generated client six months from now.
 */

it('describes every api route in the generated document', function (): void {
    $document = generatedContract();

    $undocumented = collect(Route::getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'api/'))
        ->flatMap(function ($route) use ($document) {
            $path = '/'.preg_replace('#^api/#', '', $route->uri());
            $methods = array_diff($route->methods(), ['HEAD']);

            return collect($methods)
                ->reject(fn (string $method) => isset($document['paths'][$path][strtolower($method)]))
                ->map(fn (string $method) => "{$method} {$path} ({$route->getName()})");
        })
        ->values()
        ->all();

    expect($undocumented)->toBe([]);
});

it('never describes a route the router does not have', function (): void {
    // The reverse of the assertion above: a stale contract entry for a route that
    // was renamed or removed is exactly as much drift as a missing one.
    $document = generatedContract();

    $routed = collect(Route::getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'api/'))
        ->map(fn ($route) => '/'.preg_replace('#^api/#', '', $route->uri()))
        ->unique()
        ->values()
        ->all();

    expect(array_keys($document['paths']))->each->toBeIn($routed);
});

it('matches the committed contract/openapi.json byte for byte', function (): void {
    // Structural (decoded) comparison, not a raw string diff — key order inside a
    // PHP array is not itself part of the contract, and `ci`'s own `git diff` is
    // what enforces the file on disk is exactly what this step's generation writes.
    expect(generatedContract())->toEqual(committedContract());
});

it('never exposes an internal integer id', function (): void {
    $document = generatedContract();

    $offenders = [];

    $walk = function (array $schema, string $path) use (&$walk, &$offenders): void {
        foreach ($schema['properties'] ?? [] as $name => $property) {
            if (
                ($name === 'id' || str_ends_with((string) $name, '_id'))
                && ($property['type'] ?? null) !== 'string'
                && ! (is_array($property['type'] ?? null) && in_array('string', $property['type'], true))
            ) {
                $offenders[] = "{$path}.{$name}";
            }

            if (is_array($property)) {
                $walk($property, "{$path}.{$name}");
            }
        }

        if (isset($schema['items']) && is_array($schema['items'])) {
            $walk($schema['items'], "{$path}[]");
        }
    };

    foreach ($document['components']['schemas'] ?? [] as $name => $schema) {
        $walk($schema, $name);
    }

    expect($offenders)->toBe([]);
});
