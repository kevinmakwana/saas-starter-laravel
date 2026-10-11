<?php

namespace App\Models\Concerns;

use App\Exceptions\TenancyException;
use App\Models\Team;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Marks a model as tenant-owned: reads are scoped to the current tenant (this also
 * covers route model binding) and writes outside the current tenant are refused.
 *
 * @mixin Model
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        // `saving` fires before `creating`, so stamping and checking both happen here.
        static::saving(function (Model $model): void {
            $tenantId = app(TenantContext::class)->id();

            if ($tenantId === null) {
                throw TenancyException::missingTenant($model::class);
            }

            if (! $model->exists && $model->getAttribute('tenant_id') === null) {
                $model->setAttribute('tenant_id', $tenantId);
            }

            if ((int) $model->getAttribute('tenant_id') !== $tenantId
                || ($model->exists && $model->isDirty('tenant_id'))) {
                throw TenancyException::crossTenantWrite($model::class);
            }
        });

        static::deleting(function (Model $model): void {
            if ((int) $model->getAttribute('tenant_id') !== app(TenantContext::class)->id()) {
                throw TenancyException::crossTenantWrite($model::class);
            }
        });
    }

    /** @return BelongsTo<Team, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'tenant_id');
    }
}
