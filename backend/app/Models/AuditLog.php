<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One recorded change. Nothing here needs to guard against update() or
 * delete() being called on it — a BEFORE UPDATE OR DELETE trigger rejects both
 * in the database itself, which is a stronger guarantee than anything this
 * class could add, and the one the audit trail actually depends on.
 *
 * entity_id and entity_public_id carry no foreign key: entity_type says which
 * table they name, so a single column could never reference just one.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $actor_id
 * @property string $action
 * @property string $entity_type
 * @property int|null $entity_id
 * @property string|null $entity_public_id
 * @property array<string, mixed>|null $changes
 * @property string|null $ip_address
 * @property Carbon $created_at
 * @property-read User|null $actor
 */
final class AuditLog extends Model
{
    /** @use HasFactory<AuditLogFactory> */
    use BelongsToTenant, HasFactory;

    /*
     * Eloquent's default table name convention pluralises to "audit_logs";
     * the table is named after the concept it holds, "the audit log", not
     * after a collection of rows.
     */
    protected $table = 'audit_log';

    public const ?string UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'actor_id',
        'action',
        'entity_type',
        'entity_id',
        'entity_public_id',
        'changes',
        'ip_address',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'changes' => 'array',
        ];
    }
}
