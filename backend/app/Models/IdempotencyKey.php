<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\IdempotencyKeyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One spent Idempotency-Key. Its existence is the guarantee — a tenant that
 * reserves the same key twice hits the unique constraint before a second
 * message can be written — so unlike every other model in this schema it has no
 * public identifier: nothing ever addresses this row directly, a replay is
 * handed the message it points at instead.
 *
 * message_id carries no foreign key, matching every other reference into the
 * outbox chain: messages is partitioned and has no single-column key for a
 * constraint to target. The relation below still works, because Eloquent only
 * needs matching values, not a database-level FK.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $key
 * @property string $request_hash
 * @property int $message_id
 * @property Carbon $created_at
 * @property CarbonImmutable $expires_at
 * @property-read Message $message
 */
final class IdempotencyKey extends Model
{
    /** @use HasFactory<IdempotencyKeyFactory> */
    use BelongsToTenant, HasFactory;

    public const ?string UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['key', 'request_hash', 'message_id', 'expires_at'];

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
            'expires_at' => 'immutable_datetime',
        ];
    }
}
