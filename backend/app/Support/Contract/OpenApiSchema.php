<?php

declare(strict_types=1);

namespace App\Support\Contract;

use Dedoc\Scramble\Support\Generator\Schema;
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
}
