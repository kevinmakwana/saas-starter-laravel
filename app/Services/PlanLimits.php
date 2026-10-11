<?php

namespace App\Services;

use App\Enums\Plan;
use App\Models\Project;
use App\Models\Team;
use Illuminate\Auth\Access\Response;

/** Checks plan limits and builds the friendly upgrade message used by policies. */
class PlanLimits
{
    public function usage(Team $team, string $key): int
    {
        return match ($key) {
            'projects' => app(\App\Support\Tenancy\TenantContext::class)->run($team, fn () => Project::count()),
            'members' => $team->users()->count() + $team->invitations()->whereNull('accepted_at')->where('expires_at', '>', now())->count(),
            default => 0,
        };
    }

    /** Allow when one more of $key still fits the team's plan, otherwise a 402 "upgrade" denial. */
    public function check(Team $team, string $key, string $noun): Response
    {
        $plan = $team->currentPlan();
        $limit = $plan->limit($key);
        $used = $this->usage($team, $key);

        if ($limit === null || $used < $limit) {
            return Response::allow();
        }

        $next = $plan->nextWithRoomFor($key, $used + 1);
        $hint = $next ? "Upgrade to {$next->label()} to add more." : 'Contact us for a custom plan.';

        return Response::deny("Your {$plan->label()} plan includes up to {$limit} {$noun}. {$hint}")->withStatus(402);
    }
}
