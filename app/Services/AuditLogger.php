<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;

/** Records "who did what" for the current tenant. A no-op when there is no tenant. */
class AuditLogger
{
    public function __construct(private TenantContext $tenant) {}

    public function record(string $action, string $summary, ?Model $subject = null, ?User $actor = null): void
    {
        if ($this->tenant->current() === null) {
            return;
        }

        $actor ??= auth()->user() instanceof User ? auth()->user() : null;

        AuditLog::create([
            'user_id' => $actor?->getKey(),
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'summary' => $summary,
        ]);
    }
}
