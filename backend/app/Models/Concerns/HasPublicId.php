<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * The identifier the outside world is given: a short prefix, an underscore, and a
 * ULID.
 *
 * The prefix says what the thing is in a log line or a support conversation; the
 * ULID sorts by creation time and carries no tenant information. Internal primary
 * keys stay internal — they are sequential, so exposing one would leak how many
 * of a thing exist, and they are the join keys, so exposing one invites a caller
 * to guess a neighbour.
 *
 * @phpstan-require-extends Model
 */
trait HasPublicId
{
    /**
     * The prefix this model's public identifiers carry, without the underscore.
     */
    abstract public static function publicIdPrefix(): string;

    public static function bootHasPublicId(): void
    {
        static::creating(static function (Model $model): void {
            if ($model->getAttribute('public_id') !== null) {
                return;
            }

            $model->setAttribute('public_id', static::publicIdPrefix().'_'.self::newUlid());
        });
    }

    /**
     * Lower case: a ULID is case-insensitive Crockford base32, and an identifier
     * that appears in URLs, logs and support tickets should have exactly one
     * spelling.
     */
    private static function newUlid(): string
    {
        return strtolower((string) Str::ulid());
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
