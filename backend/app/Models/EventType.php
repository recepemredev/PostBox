<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\EventTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An event a tenant publishes. The name is the identifier — a producer sends it,
 * the SDK types are generated from it, and it stays fixed once consumers depend
 * on it. Unique within the tenant; its shape is enforced by a database CHECK.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class EventType extends Model
{
    /** @use HasFactory<EventTypeFactory> */
    use BelongsToTenant, HasFactory;

    /** @var list<string> */
    protected $fillable = ['name'];

    /**
     * @return HasMany<EndpointSubscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(EndpointSubscription::class);
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }
}
