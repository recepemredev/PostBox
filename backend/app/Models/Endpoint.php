<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EndpointStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicId;
use Database\Factories\EndpointFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A destination an application delivers to: a URL and the operator's switch for
 * whether it should receive traffic. Signing secrets, event subscriptions and
 * circuit breaker state each belong to their own table, so this row describes
 * intent and nothing about the last delivery.
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property int $application_id
 * @property string $name
 * @property string $url
 * @property EndpointStatus $status
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Application $application
 * @property-read EndpointCircuitBreaker|null $breaker
 */
final class Endpoint extends Model
{
    /** @use HasFactory<EndpointFactory> */
    use BelongsToTenant, HasFactory, HasPublicId;

    /** @var list<string> */
    protected $fillable = ['name', 'url', 'status'];

    public static function publicIdPrefix(): string
    {
        return 'ep';
    }

    /**
     * @return BelongsTo<Application, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /**
     * @return HasMany<EndpointSecret, $this>
     */
    public function secrets(): HasMany
    {
        return $this->hasMany(EndpointSecret::class);
    }

    /**
     * @return HasMany<EndpointSubscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(EndpointSubscription::class);
    }

    /**
     * @return HasMany<Delivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class);
    }

    /**
     * Absent for an endpoint that has never tripped — closed is the default a
     * missing row means, not a row this relation has to produce.
     *
     * @return HasOne<EndpointCircuitBreaker, $this>
     */
    public function breaker(): HasOne
    {
        return $this->hasOne(EndpointCircuitBreaker::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => EndpointStatus::class,
        ];
    }
}
