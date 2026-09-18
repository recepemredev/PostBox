<?php

declare(strict_types=1);

namespace App\Support\Contract;

use Dedoc\Scramble\Contracts\OperationTransformer;
use Dedoc\Scramble\Support\Generator\Header;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\RouteInfo;

/**
 * Describes what the three `governor`-guarded write routes (ingest, message replay,
 * range replay) add on top of what static analysis of the controller sees: the
 * `Idempotency-Key` request header — merged into validated input by each Form
 * Request's own `prepareForValidation()`, so Scramble infers it as a body field
 * instead — and the 200-vs-201 status pair every one of them answers with
 * (MessageController, ReplayController: a fresh write is 201, a replay of an
 * already-seen key is 200 with `Idempotent-Replay: true`, same body either way).
 * `EnforceLimits` carries the same six RateLimit-* and Quota-* headers on both.
 */
final class DescribeIdempotentWrites implements OperationTransformer
{
    /** @var list<string> */
    private const array RATE_LIMIT_HEADERS = ['RateLimit-Limit', 'RateLimit-Remaining', 'RateLimit-Reset'];

    /** @var list<string> */
    private const array QUOTA_HEADERS = ['Quota-Limit', 'Quota-Remaining', 'Quota-Reset'];

    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        if (! in_array('governor', $routeInfo->route->gatherMiddleware(), true)) {
            return;
        }

        $this->moveIdempotencyKeyToHeader($operation);
        $this->describeDualStatusResponse($operation);
        $this->describeGovernorErrors($operation);

        // ReplayTargetNotFound (404) is Recovery's own — nothing an ingest publish can
        // throw, since messages.store's only 404 is "no such application" and Scramble
        // already finds that one through route model binding.
        if (in_array($routeInfo->route->getName(), ['replays.message', 'replays.range'], true)) {
            $operation->addResponse($this->errorResponse(
                404,
                'No original delivery exists for this message and endpoint.',
            ));
        }
    }

    private function moveIdempotencyKeyToHeader(Operation $operation): void
    {
        // Not chained: StringType::setMax() is vendor code with no declared return
        // type, so Larastan sees `mixed` the moment a call chains through it — kept
        // as statements against the one still-typed $key variable instead.
        $key = new StringType;
        $key->setMax(255);
        $key->pattern('^[\x21-\x7e]+$');

        // Parameter::description() has the same undeclared-return-type shape, so this
        // one is built the same way: statements against $parameter, never a chain.
        $parameter = Parameter::make('Idempotency-Key', 'header');
        $parameter->description(
            'An optional key scoped to this tenant. Replaying it with an '
            .'identical body returns the original result unchanged (200, '
            .'Idempotent-Replay: true); a different body answers 409.'
        );
        $parameter->setSchema(OpenApiSchema::of($key));

        $operation->addParameters([$parameter]);

        // Each of the three governed routes has its own Form Request, so Scramble
        // deduplicates its body into its own named component schema and the operation
        // only holds a Reference to it — resolving is what reaches the real,
        // still-mutable Schema instance registered in the document's components.
        $body = $operation->requestBodyObject?->content['application/json'] ?? null;

        if ($body instanceof Reference) {
            $body = $body->resolve();
        }

        $type = $body instanceof Schema ? $body->type : null;

        if ($type instanceof ObjectType) {
            unset($type->properties['idempotency_key']);
            $type->required = array_values(array_diff($type->required, ['idempotency_key']));
        }
    }

    private function describeDualStatusResponse(Operation $operation): void
    {
        $success = null;

        foreach ($operation->responses ?? [] as $response) {
            if ($response instanceof Response && is_int($response->code) && $response->code >= 200 && $response->code < 300) {
                $success = $response;
                break;
            }
        }

        if ($success === null) {
            return;
        }

        $operation->responses = array_values(array_filter(
            $operation->responses ?? [],
            static fn ($response) => $response !== $success,
        ));

        $created = clone $success;
        $created->code = 201;
        $created->description = 'Accepted: this request produced a new record.';

        $replayed = clone $success;
        $replayed->code = 200;
        $replayed->description = 'Idempotent replay: an identical earlier request already produced this result.';
        $replayed->addHeader('Idempotent-Replay', (new Header(
            description: 'Present, and always "true", only on the replayed response.',
            schema: OpenApiSchema::of((new StringType)->enum(['true'])),
        )));

        foreach ([$created, $replayed] as $response) {
            $this->addLimitHeaders($response);
            $operation->addResponse($response);
        }
    }

    private function addLimitHeaders(Response $response): void
    {
        $descriptions = [
            'RateLimit-Limit' => 'The token bucket capacity for this tenant.',
            'RateLimit-Remaining' => 'Tokens left in the current bucket.',
            'RateLimit-Reset' => 'Seconds until the bucket next refills.',
            'Quota-Limit' => 'The cumulative quota for the current billing period.',
            'Quota-Remaining' => 'Quota left in the current billing period.',
            'Quota-Reset' => 'Seconds until the current billing period resets.',
        ];

        foreach ([...self::RATE_LIMIT_HEADERS, ...self::QUOTA_HEADERS] as $name) {
            $response->addHeader($name, new Header(
                description: $descriptions[$name],
                schema: OpenApiSchema::of(new IntegerType),
            ));
        }
    }

    /**
     * IdempotencyConflict (409), RateLimitExceeded (429) and QuotaExhausted (402) are
     * thrown from EnforceLimits and the idempotency reservation — middleware and a
     * concern the Form Request calls into, neither one reachable from static analysis
     * of the controller action, so Scramble's own exception inference never sees them.
     */
    private function describeGovernorErrors(Operation $operation): void
    {
        $operation->addResponse($this->errorResponse(
            409,
            'This Idempotency-Key was already used for a request with a different body.',
        ));

        $rateLimited = $this->errorResponse(429, 'The rate limit for this tenant has been exceeded.');
        $rateLimited->addHeader('Retry-After', new Header(
            description: 'Seconds until the bucket next refills.',
            schema: OpenApiSchema::of(new IntegerType),
        ));
        $this->addLimitHeaders($rateLimited);
        $operation->addResponse($rateLimited);

        $quotaExhausted = $this->errorResponse(
            402,
            "This tenant's quota for the current billing period has been exhausted.",
        );
        $this->addLimitHeaders($quotaExhausted);
        $operation->addResponse($quotaExhausted);
    }

    private function errorResponse(int $code, string $description): Response
    {
        $message = new ObjectType;
        $message->addProperty('message', new StringType);
        $message->setRequired(['message']);

        return (new Response($code))
            ->setDescription($description)
            ->setContent('application/json', OpenApiSchema::of($message));
    }
}
