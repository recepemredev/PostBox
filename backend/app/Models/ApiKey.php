<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A credential for the ingest surface, belonging to a tenant rather than to a
 * person: the producer calling the API is a system, and systems outlive the
 * people who set them up.
 *
 * The secret is never stored. What is stored is a SHA-256 of the whole token —
 * fast on purpose, because this hash is verified on the hot path of every ingest
 * request and the input is 256 bits of randomness, not a password. Stretching a
 * value that is already unguessable buys nothing and costs milliseconds per call.
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property string $name
 * @property string $token_hash
 * @property string $last_four
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $revoked_at
 * @property int|null $created_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class ApiKey extends Model
{
    use BelongsToTenant, HasPublicId;

    /** @var list<string> */
    protected $fillable = ['name'];

    /** @var list<string> */
    protected $hidden = ['token_hash'];

    public static function publicIdPrefix(): string
    {
        return 'key';
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Neither revoked nor expired. Authentication looks a key up through this
     * scope, so a rejected key and an unknown key take the same path and produce
     * the same answer — the caller learns nothing from the difference.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeUsable(Builder $query): void
    {
        $query->whereNull('revoked_at')->where(
            static fn (Builder $key): Builder => $key
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', now()),
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_used_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }
}
