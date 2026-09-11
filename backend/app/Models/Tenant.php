<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Plan;
use App\Models\Concerns\HasPublicId;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The root of the boundary, and the one table that is not behind it: a tenant row
 * is what a policy compares against, so it cannot itself be invisible until a
 * tenant is current. Nothing reaches it except through a membership or an API key.
 *
 * @property int $id
 * @property string $public_id
 * @property string $name
 * @property Plan $plan
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory, HasPublicId;

    /** @var list<string> */
    protected $fillable = ['name', 'plan'];

    public static function publicIdPrefix(): string
    {
        return 'ten';
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
            'plan' => Plan::class,
        ];
    }
}
