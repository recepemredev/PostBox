<?php

declare(strict_types=1);

namespace App\Support\Contract;

use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\Generator\Types\Type;

/**
 * dedoc/scramble's own `Schema::fromType()` carries no declared return type, and
 * vendor code is reflection-only to Larastan (never analyzed, so nothing there can be
 * inferred from a method body) — every call site would otherwise narrow the same
 * `mixed` the same way, so it is unified here once rather than repeated at each of
 * the transformer classes that build a schema by hand.
 */
final class OpenApiSchema
{
    public static function of(Type $type): Schema
    {
        $schema = Schema::fromType($type);
        assert($schema instanceof Schema);

        return $schema;
    }

    /**
     * The `{"message": string}` shape every error response this application
     * throws actually renders (bootstrap/app.php's `shouldRenderJsonWhen`).
     * Unified here at DescribeEventStream's second occurrence of
     * DescribeIdempotentWrites's own private method of the same shape,
     * rather than a third copy of it (CLAUDE.md: duplicated logic is
     * unified at the second occurrence).
     */
    public static function errorResponse(int $code, string $description): Response
    {
        $message = new ObjectType;
        $message->addProperty('message', new StringType);
        $message->setRequired(['message']);

        return (new Response($code))
            ->setDescription($description)
            ->setContent('application/json', self::of($message));
    }
}
