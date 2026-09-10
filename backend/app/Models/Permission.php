<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One thing a role is allowed to do. The code is the contract with the
 * application: PermissionCode names it in PHP, this table joins it to a role, and
 * a test asserts the two never drift apart.
 *
 * @property int $id
 * @property string $code
 */
final class Permission extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [];

    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }
}
