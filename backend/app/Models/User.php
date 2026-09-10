<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;

/**
 * A person, not a tenant's property: the same identity can hold a membership in
 * more than one tenant, so the row itself sits outside the boundary and every
 * question about what it may do is asked of a membership.
 *
 * @property int $id
 * @property string $public_id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasPublicId;

    /**
     * There is no "remember me": a dashboard session is deliberately short-lived,
     * and an empty name here is how the framework is told the column does not
     * exist rather than left to look for one.
     *
     * @var string
     */
    protected $rememberTokenName = '';

    /** @var list<string> */
    protected $fillable = ['name', 'email', 'password'];

    /** @var list<string> */
    protected $hidden = ['password'];

    public static function publicIdPrefix(): string
    {
        return 'usr';
    }

    /**
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }
}
