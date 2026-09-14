<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Database\Factories\ReplayFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A recovery request: what an operator asked to have retried, and how many
 * deliveries that produced. It never carries a delivery's own history — the
 * deliveries relation below is where that lives — so this row stays exactly
 * as small as the receipt it is.
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property string|null $idempotency_key
 * @property string|null $request_hash
 * @property int|null $message_id
 * @property int|null $endpoint_id
 * @property CarbonImmutable|null $range_from
 * @property CarbonImmutable|null $range_to
 * @property int $delivery_count
 * @property Carbon $created_at
 * @property-read Endpoint|null $endpoint
 * @property-read Message|null $message
 */
final class Replay extends Model
{
    /** @use HasFactory<ReplayFactory> */
    use BelongsToTenant, HasFactory, HasPublicId;

    public const ?string UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'idempotency_key',
        'request_hash',
        'message_id',
        'endpoint_id',
        'range_from',
        'range_to',
        'delivery_count',
    ];

    public static function publicIdPrefix(): string
    {
        return 'rpl';
    }

    /**
     * @return BelongsTo<Message, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /**
     * @return BelongsTo<Endpoint, $this>
     */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(Endpoint::class);
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
            'range_from' => 'immutable_datetime',
            'range_to' => 'immutable_datetime',
        ];
    }
}
