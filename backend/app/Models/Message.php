<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MessageSource;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicId;
use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One published event, exactly as the producer sent it. The table is
 * range-partitioned by month, which is why its uniqueness guarantees are scoped
 * to created_at at the database level — public_id stays unique in practice
 * because a ULID's entropy makes a collision astronomically unlikely, not
 * because a single index spans every partition.
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property int $application_id
 * @property int $event_type_id
 * @property array<string, mixed> $payload
 * @property MessageSource $source
 * @property Carbon $created_at
 * @property Carbon|null $updated_at
 * @property-read Application $application
 * @property-read EventType $eventType
 * @property-read int $deliveries_count from MessageController's own
 *                withCount('deliveries') — Larastan's Laravel plugin only
 *                infers the plain, unaliased form of that call
 * @property-read int $succeeded_deliveries_count from MessageController's
 *                own aliased withCount(['deliveries as
 *                succeeded_deliveries_count' => ...])
 * @property-read int $exhausted_deliveries_count from MessageController's
 *                own aliased withCount(['deliveries as
 *                exhausted_deliveries_count' => ...])
 */
final class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use BelongsToTenant, HasFactory, HasPublicId;

    /** @var list<string> */
    protected $fillable = ['application_id', 'event_type_id', 'payload', 'source'];

    public static function publicIdPrefix(): string
    {
        return 'msg';
    }

    /**
     * @return BelongsTo<Application, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /**
     * @return BelongsTo<EventType, $this>
     */
    public function eventType(): BelongsTo
    {
        return $this->belongsTo(EventType::class);
    }

    /**
     * @return HasMany<Delivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'source' => MessageSource::class,
        ];
    }
}
