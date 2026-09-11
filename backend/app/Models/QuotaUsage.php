<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\QuotaUsageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * How much of one calendar month a tenant has spent against their quota. A row
 * exists only for a period the tenant has actually published into — nothing
 * seeds one ahead of time.
 *
 * @property int $id
 * @property int $tenant_id
 * @property Carbon $period_start
 * @property int $used
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class QuotaUsage extends Model
{
    /** @use HasFactory<QuotaUsageFactory> */
    use BelongsToTenant, HasFactory;

    protected $table = 'quota_usage';

    /** @var list<string> */
    protected $fillable = ['period_start', 'used'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start' => 'date',
        ];
    }
}
