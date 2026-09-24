<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BreakerState;
use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\EndpointCircuitBreakerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One endpoint's circuit breaker. A row exists only once a breaker has opened
 * at least once — an endpoint that has never tripped is closed by the absence
 * of a row, the same lazy shape QuotaUsage takes.
 *
 * TransitionBreaker is the only writer. Nothing here enforces the transition
 * table itself; that belongs to BreakerState, which a plain Eloquent model has
 * no business re-deciding.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $endpoint_id
 * @property BreakerState $state
 * @property CarbonImmutable $state_changed_at
 * @property CarbonImmutable|null $opened_at
 * @property CarbonImmutable|null $probe_started_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Endpoint $endpoint
 */
final class EndpointCircuitBreaker extends Model
{
    /** @use HasFactory<EndpointCircuitBreakerFactory> */
    use BelongsToTenant, HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'endpoint_id',
        'state',
        'state_changed_at',
        'opened_at',
        'probe_started_at',
    ];

    /**
     * @return BelongsTo<Endpoint, $this>
     */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(Endpoint::class);
    }

    /**
     * Every currently-tripped breaker — open or half-open — most recently
     * changed first. Shared by the operator's own shell command
     * (BreakerCommand) and the dashboard's operations read (Step 17): both
     * ask the same question, "what does an operator need to act on right
     * now", and a closed breaker has nothing left on it to act on even
     * though its row still exists.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeTripped(Builder $query): void
    {
        $query->whereIn('state', [BreakerState::Open, BreakerState::HalfOpen])->orderByDesc('state_changed_at');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => BreakerState::class,
            'state_changed_at' => 'immutable_datetime',
            'opened_at' => 'immutable_datetime',
            'probe_started_at' => 'immutable_datetime',
        ];
    }
}
