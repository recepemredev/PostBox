<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DeliveryStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Database\Factories\DeliveryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One endpoint's standing obligation to receive one message. The fan-out that
 * creates these opens exactly one per subscribed endpoint; message_id is a plain
 * column rather than a foreign key, because messages is partitioned and has no
 * single-column key for a constraint to reference — the relation below still
 * works, since Eloquent only needs matching values, not a database-level FK.
 *
 * replay_id is null for the row PublishMessage's own fan-out opened, and set
 * for one Recovery opened instead: a replay is a second obligation against the
 * same message and endpoint, never a change to the first. Nothing in this
 * class treats the two differently — a replay clears its own attempts, its own
 * breaker admission and its own retry budget exactly the way an original does,
 * because it is an ordinary pending row in every way but where it came from.
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property int $message_id
 * @property int $endpoint_id
 * @property int|null $replay_id
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
 * @property-read Replay|null $replay
 * @property-read Collection<int, DeliveryAttempt> $attempts
 */
final class Delivery extends Model
{
    /** @use HasFactory<DeliveryFactory> */
    use BelongsToTenant, HasFactory, HasPublicId;

    /** @var list<string> */
    protected $fillable = [
        'message_id',
        'endpoint_id',
        'replay_id',
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
     * Null for the fan-out's own row. Set for one Recovery opened instead.
     *
     * @return BelongsTo<Replay, $this>
     */
    public function replay(): BelongsTo
    {
        return $this->belongsTo(Replay::class);
    }

    /**
     * The retry timeline (Step 14): this delivery's own attempts, in order.
     * No foreign key behind it, matching every other reference into the
     * partitioned tables — delivery_attempts.delivery_id is an
     * application-level fact here, not a database-level one.
     *
     * @return HasMany<DeliveryAttempt, $this>
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(DeliveryAttempt::class);
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
