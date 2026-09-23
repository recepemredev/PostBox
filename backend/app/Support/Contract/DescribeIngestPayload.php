<?php

declare(strict_types=1);

namespace App\Support\Contract;

use Dedoc\Scramble\Contracts\OperationTransformer;
use Dedoc\Scramble\Support\Generator\Combined\AnyOf;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\MixedType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\RouteInfo;

/**
 * PublishMessageRequest's `payload` rule is `['required', 'array']` — accurate for
 * validation (PHP has one array type for both), but Scramble's static analysis has
 * no PHP type to infer an item shape from and defaults to "array of strings". The
 * real shape is whatever `json_decode` accepts as an object or a list: any JSON
 * value that isn't a bare scalar. Fixed here rather than by a richer validation
 * rule, because the schema Scramble infers and the rule that enforces it are two
 * different concerns — WithinPayloadCeiling stays about size, not shape.
 */
final class DescribeIngestPayload implements OperationTransformer
{
    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        if ($routeInfo->route->getName() !== 'messages.store') {
            return;
        }

        // Same reference-resolution as DescribeIdempotentWrites::moveIdempotencyKeyToHeader:
        // the operation only holds a Reference to the Form Request's own named
        // component schema, so the real, still-mutable Schema instance is reached
        // by resolving it against the document's components.
        $body = $operation->requestBodyObject?->content['application/json'] ?? null;

        if ($body instanceof Reference) {
            $body = $body->resolve();
        }

        $type = $body instanceof Schema ? $body->type : null;

        if ($type instanceof ObjectType && $type->hasProperty('payload')) {
            $type->addProperty('payload', $this->payloadType());
        }
    }

    private function payloadType(): AnyOf
    {
        $object = new ObjectType;
        $object->setDescription('A JSON object.');

        $array = new ArrayType;
        $array->setItems(new MixedType);
        $array->setMin(1);
        $array->setDescription('A non-empty JSON array.');

        // Not chained: AnyOf::setItems(), like StringType::setMax() elsewhere in
        // this namespace, is vendor code with no declared return type, so Larastan
        // sees `mixed` the moment a call chains through it — kept as a statement
        // against the still-typed $anyOf variable instead.
        $anyOf = new AnyOf;
        $anyOf->setItems([$object, $array]);

        return $anyOf;
    }
}
