<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int|null $user_id
 * @property Carbon $created_at
 */
class AuditLog extends Model
{
    use BelongsToTenant, MassPrunable;

    public const UPDATED_AT = null;

    /**
     * Entries are append-only for application code. Retention pruning (a mass query delete) and the
     * database cascade when a team is deleted are the only ways a row goes away.
     */
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit log entries cannot be modified.'));
        static::deleting(fn () => throw new LogicException('Audit log entries cannot be deleted.'));
    }

    /** @var list<string> */
    protected $fillable = ['user_id', 'action', 'subject_type', 'subject_id', 'summary'];

    /**
     * Pruning runs outside any tenant, so it bypasses the tenant scope. With no retention configured it matches nothing.
     *
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        $days = config('retention.audit_logs_days');
        $query = static::withoutGlobalScopes();

        return $days ? $query->where('created_at', '<', now()->subDays((int) $days)) : $query->whereRaw('1 = 0');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
