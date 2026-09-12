<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DeliveryStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Database\Factories\DeliveryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One endpoint's standing obligation to receive one message. The fan-out that
 * creates these opens exactly one per subscribed endpoint; message_id is a plain
 * column rather than a foreign key, because messages is partitioned and has no
 * single-column key for a constraint to reference — the relation below still
 * works, since Eloquent only needs matching values, not a database-level FK.
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property int $message_id
 * @property int $endpoint_id
 * @property DeliveryStatus $status
 * @property int $attempt_count
 * @property CarbonImmutable|null $next_attempt_at
 * @property CarbonImmutable|null $last_attempted_at
 * @property CarbonImmutable|null $exhausted_at
 * @property string|null $failure_reason
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Endpoint $endpoint
 * @property-read Message $message
 */
final class Delivery extends Model
{
    /** @use HasFactory<DeliveryFactory> */
    use BelongsToTenant, HasFactory, HasPublicId;

    /** @var list<string> */
    protected $fillable = [
        'message_id',
        'endpoint_id',
        'status',
        'attempt_count',
        'next_attempt_at',
        'last_attempted_at',
        'exhausted_at',
        'failure_reason',
    ];

    public static function publicIdPrefix(): string
    {
        return 'dlv';
    }

    /**
     * The dead letter queue. It is a predicate rather than a table because an
     * exhausted delivery is the same row it always was — nothing about it moves
     * anywhere, which is also what makes "never deleted by the delivery path"
     * true by construction rather than by discipline.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeDeadLettered(Builder $query): void
    {
        $query->where('status', DeliveryStatus::Exhausted);
    }

    /**
     * @return BelongsTo<Endpoint, $this>
     */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(Endpoint::class);
    }

    /**
     * @return BelongsTo<Message, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DeliveryStatus::class,
            'next_attempt_at' => 'immutable_datetime',
            'last_attempted_at' => 'immutable_datetime',
            'exhausted_at' => 'immutable_datetime',
        ];
    }
}
