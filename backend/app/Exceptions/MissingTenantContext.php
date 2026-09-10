<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown where the code assumed a tenant was current and none was. It is a defect,
 * not a condition to handle: a write with no tenant would be a row belonging to
 * nobody, and the database would reject it a moment later anyway.
 */
final class MissingTenantContext extends RuntimeException
{
    public static function forWrite(string $model): self
    {
        return new self("No tenant is current, so [{$model}] cannot be written.");
    }

    public static function forRead(): self
    {
        return new self('No tenant is current.');
    }
}
