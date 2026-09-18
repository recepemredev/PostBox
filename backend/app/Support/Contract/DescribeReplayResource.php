<?php

declare(strict_types=1);

namespace App\Support\Contract;

use App\Http\Resources\ReplayResource;
use Dedoc\Scramble\Contracts\DocumentTransformer;
use Dedoc\Scramble\OpenApiContext;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;

/**
 * ReplayResource::toArray() spreads `next_cursor` in conditionally on whether the
 * replay was a range scope (D83) — a shape static analysis of an array spread cannot
 * follow, so Scramble infers the schema without that field and, unsure of the rest,
 * widens every other property to "any type or absent" rather than the closed shape
 * the class actually always returns. Replaced here with the real one, computed once
 * for both replay routes since they share this one component schema.
 */
final class DescribeReplayResource implements DocumentTransformer
{
    public function handle(OpenApi $document, OpenApiContext $context): void
    {
        // Scramble keys a Resource's own schema by its short class name (unlike a Form
        // Request's, which it keeps fully qualified until serialization) — matching on
        // the short name is what survives that inconsistency.
        $key = array_find_key(
            $document->components->schemas,
            static fn ($schema, string $fullName) => class_basename($fullName) === class_basename(ReplayResource::class),
        );

        if ($key === null) {
            return;
        }

        $document->components->schemas[$key] = OpenApiSchema::of($this->replayResultType());
    }

    private function replayResultType(): ObjectType
    {
        $id = (new StringType)->setDescription('The replay receipt, rpl_...');
        $nullableId = (clone $id)->nullable(true);

        $type = new ObjectType;
        $type->addProperty('id', $id);
        $type->addProperty('message_id', (clone $nullableId)->setDescription('Set for a single-message replay.'));
        $type->addProperty('endpoint_id', (clone $nullableId)->setDescription('Set for a single-message replay to one endpoint, or a range replay.'));
        $type->addProperty('range_from', (new StringType)->format('date-time')->nullable(true)->setDescription('Set for a range replay.'));
        $type->addProperty('range_to', (new StringType)->format('date-time')->nullable(true)->setDescription('Set for a range replay.'));
        $type->addProperty('delivery_count', new IntegerType);
        $type->addProperty('created_at', (new StringType)->format('date-time'));
        $type->addProperty('next_cursor', (new StringType)->nullable(true)->setDescription(
            'Present only for a range replay — absent (not null) for a single message, '
            .'since pagination never applied to it in the first place.'
        ));

        $type->setRequired(['id', 'message_id', 'endpoint_id', 'range_from', 'range_to', 'delivery_count', 'created_at']);

        return $type;
    }
}
