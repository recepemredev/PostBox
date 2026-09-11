<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicId;
use Database\Factories\ApplicationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A named grouping an event is published into: producers send to an application,
 * endpoints subscribe beneath it, and every message and secret hangs from one.
 * It holds no delivery behaviour of its own — it is how a tenant keeps their
 * integrations apart, and the parent in every route under /v1/apps/{app}.
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property string $name
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class Application extends Model
{
    /** @use HasFactory<ApplicationFactory> */
    use BelongsToTenant, HasFactory, HasPublicId;

    /** @var list<string> */
    protected $fillable = ['name'];

    public static function publicIdPrefix(): string
    {
        return 'app';
    }

    /**
     * @return HasMany<Endpoint, $this>
     */
    public function endpoints(): HasMany
    {
        return $this->hasMany(Endpoint::class);
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }
}
