<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use App\Services\PlanLimits;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\Response;

class ProjectPolicy
{
    public function __construct(private TenantContext $tenant, private PlanLimits $limits) {}

    public function viewAny(User $user): bool
    {
        return $this->tenant->current() !== null;
    }

    public function view(User $user, Project $project): bool
    {
        return $this->belongs($project);
    }

    /** Members may create projects until the plan limit is reached. */
    public function create(User $user): Response
    {
        $team = $this->tenant->current();

        return $team === null ? Response::deny() : $this->limits->check($team, 'projects', 'projects');
    }

    public function update(User $user, Project $project): bool
    {
        return $this->belongs($project);
    }

    /** Deleting is limited to owners and admins. */
    public function delete(User $user, Project $project): bool
    {
        $team = $this->tenant->current();

        return $this->belongs($project) && $team !== null && ($team->roleOf($user)?->canManageTeam() ?? false);
    }

    private function belongs(Project $project): bool
    {
        return $this->tenant->id() === (int) $project->tenant_id;
    }
}
