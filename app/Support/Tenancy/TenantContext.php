<?php

namespace App\Support\Tenancy;

use App\Models\Team;
use App\Models\User;
use Closure;

/**
 * Resolves the current tenant: an explicit override (jobs, seeders, tests)
 * or else the authenticated user's current team, provided they still belong to it.
 * With no tenant the TenantScope fails closed and matches nothing.
 */
final class TenantContext
{
    private ?Team $override = null;

    private bool $hasOverride = false;

    /** @var array<string, bool> */
    private array $membership = [];

    public function current(): ?Team
    {
        if ($this->hasOverride) {
            return $this->override;
        }

        $user = auth()->user();
        if (! $user instanceof User || $user->current_team_id === null) {
            return null;
        }

        $team = $user->currentTeam;
        if ($team === null) {
            return null;
        }

        $key = $user->getKey().':'.$team->getKey();
        $this->membership[$key] ??= $team->hasMember($user);

        return $this->membership[$key] ? $team : null;
    }

    public function id(): ?int
    {
        $id = $this->current()?->getKey();

        return $id === null ? null : (int) $id;
    }

    public function set(?Team $team): void
    {
        $this->override = $team;
        $this->hasOverride = true;
    }

    public function forget(): void
    {
        $this->override = null;
        $this->hasOverride = false;
        $this->membership = [];
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(?Team $team, Closure $callback): mixed
    {
        [$previous, $had] = [$this->override, $this->hasOverride];
        $this->set($team);

        try {
            return $callback();
        } finally {
            $this->override = $previous;
            $this->hasOverride = $had;
        }
    }
}
