<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\EndpointSubscriptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An endpoint's standing request to receive one event type. A plain join: the
 * pair is unique, and a publish is fanned out to exactly the endpoints with a row
 * here for the event being sent.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $endpoint_id
 * @property int $event_type_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Endpoint $endpoint
 * @property-read EventType $eventType
 */
final class EndpointSubscription extends Model
{
    /** @use HasFactory<EndpointSubscriptionFactory> */
    use BelongsToTenant, HasFactory;

    /** @var list<string> */
    protected $fillable = ['endpoint_id', 'event_type_id'];

    /**
     * @return BelongsTo<Endpoint, $this>
     */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(Endpoint::class);
    }

    /**
     * @return BelongsTo<EventType, $this>
     */
    public function eventType(): BelongsTo
    {
        return $this->belongsTo(EventType::class);
    }
}
