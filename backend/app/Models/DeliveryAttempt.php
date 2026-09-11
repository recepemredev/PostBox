<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AttemptOutcome;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicId;
use Database\Factories\DeliveryAttemptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One HTTP attempt at one delivery, kept exactly as it happened. Nothing in the
 * application ever asks to change a row here once it exists — hence no
 * updated_at, the schema's own way of saying so.
 *
 * delivery_id and endpoint_id carry no foreign key, matching
 * deliveries.message_id: the table is partitioned, so a plain single-column
 * reference is an application-level fact here, not a database-level one.
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property int $delivery_id
 * @property int $endpoint_id
 * @property int $attempt_number
 * @property AttemptOutcome $outcome
 * @property array<string, mixed> $request_headers
 * @property string $request_body
 * @property int|null $response_status
 * @property array<string, mixed>|null $response_headers
 * @property string|null $response_body
 * @property string|null $error_message
 * @property int $duration_ms
 * @property Carbon $created_at
 * @property-read Delivery $delivery
 * @property-read Endpoint $endpoint
 */
final class DeliveryAttempt extends Model
{
    /** @use HasFactory<DeliveryAttemptFactory> */
    use BelongsToTenant, HasFactory, HasPublicId;

    public const ?string UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'delivery_id',
        'endpoint_id',
        'attempt_number',
        'outcome',
        'request_headers',
        'request_body',
        'response_status',
        'response_headers',
        'response_body',
        'error_message',
        'duration_ms',
    ];

    public static function publicIdPrefix(): string
    {
        return 'att';
    }

    /**
     * @return BelongsTo<Delivery, $this>
     */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    /**
     * @return BelongsTo<Endpoint, $this>
     */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(Endpoint::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'outcome' => AttemptOutcome::class,
            'request_headers' => 'array',
            'response_headers' => 'array',
        ];
    }
}
