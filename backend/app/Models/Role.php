<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A named bundle of permissions, shared by every tenant. The rows are written by
 * the migration that creates the table, not by a seeder: the application cannot
 * authorize anything without them, so they are schema in every sense that matters.
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property-read Collection<int, Permission> $permissions
 */
final class Role extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [];

    /**
     * @return BelongsToMany<Permission, $this>
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }
}
