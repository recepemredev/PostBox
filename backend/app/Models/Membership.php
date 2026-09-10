<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\MembershipFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A user's place in a tenant, and the role they hold there.
 *
 * It is tenant-owned like everything else, with one addition: a policy on the
 * table also lets a user read their own rows while no tenant is current. That is
 * the only way to answer "which tenants is this person in?" at the moment after
 * authentication and before a tenant has been chosen.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $user_id
 * @property int $role_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Tenant $tenant
 * @property-read User $user
 * @property-read Role $role
 */
final class Membership extends Model
{
    /** @use HasFactory<MembershipFactory> */
    use BelongsToTenant, HasFactory;

    /** @var list<string> */
    protected $fillable = ['user_id', 'role_id'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
