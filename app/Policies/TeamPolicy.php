<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Team;
use App\Models\User;

class TeamPolicy
{
    public function view(User $user, Team $team): bool
    {
        return $team->hasMember($user);
    }

    public function update(User $user, Team $team): bool
    {
        return $team->roleOf($user)?->canManageTeam() ?? false;
    }

    public function delete(User $user, Team $team): bool
    {
        return $team->roleOf($user) === Role::Owner;
    }

    public function invite(User $user, Team $team): bool
    {
        return $team->roleOf($user)?->canManageTeam() ?? false;
    }

    /** Owners can invite admins and members, admins only members. */
    public function inviteAs(User $user, Team $team, Role $role): bool
    {
        return match ($team->roleOf($user)) {
            Role::Owner => in_array($role, Role::invitable(), true),
            Role::Admin => $role === Role::Member,
            default => false,
        };
    }

    public function updateMemberRole(User $user, Team $team): bool
    {
        return $team->roleOf($user) === Role::Owner;
    }

    /** Owners remove anyone else, admins remove members, anyone may leave unless they own the team. */
    public function removeMember(User $user, Team $team, User $target): bool
    {
        $targetRole = $team->roleOf($target);
        if ($targetRole === null || $targetRole === Role::Owner) {
            return false;
        }

        if ($user->is($target)) {
            return true;
        }

        return match ($team->roleOf($user)) {
            Role::Owner => true,
            Role::Admin => $targetRole === Role::Member,
            default => false,
        };
    }

    public function transferOwnership(User $user, Team $team): bool
    {
        return $team->roleOf($user) === Role::Owner;
    }

    public function viewAuditLog(User $user, Team $team): bool
    {
        return $team->roleOf($user)?->canManageTeam() ?? false;
    }
}
