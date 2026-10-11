<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

class TaskPolicy
{
    public function __construct(private TenantContext $tenant) {}

    public function manage(User $user, Task $task): bool
    {
        return $this->tenant->id() === (int) $task->tenant_id;
    }
}
