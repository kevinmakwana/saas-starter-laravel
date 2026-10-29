<?php

namespace App\Services;

use App\Enums\Plan;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\Team;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\Response;

/** Checks plan limits and builds the friendly upgrade message used by policies. */
class PlanLimits
{
    private const MB = 1048576;

    /** Bytes of attachments the team stores. */
    public function storedBytes(Team $team): int
    {
        return (int) app(TenantContext::class)->run($team, fn () => Attachment::sum('size'));
    }

    /** Allow a file of $bytes when it still fits the plan's storage, otherwise a 402 "upgrade" denial. */
    public function checkStorage(Team $team, int $bytes): Response
    {
        $plan = $team->currentPlan();
        $limit = $plan->limit('storage_mb');
        $total = $this->storedBytes($team) + $bytes;

        if ($limit === null || $total <= $limit * self::MB) {
            return Response::allow();
        }

        $next = $plan->nextWithRoomFor('storage_mb', (int) ceil($total / self::MB));
        $hint = $next ? __('Upgrade to :plan for more.', ['plan' => $next->label()]) : __('Contact us for a custom plan.');

        return Response::deny(__('Your :plan plan includes :limit MB of file storage. :hint', ['plan' => $plan->label(), 'limit' => $limit, 'hint' => $hint]))->withStatus(402);
    }

    public function usage(Team $team, string $key): int
    {
        return match ($key) {
            'projects' => app(TenantContext::class)->run($team, fn () => Project::count()),
            'storage_mb' => (int) ceil($this->storedBytes($team) / self::MB),
            'members' => $team->users()->count() + $team->invitations()->whereNull('accepted_at')->where('expires_at', '>', now())->count(),
            default => 0,
        };
    }

    /**
     * Limits the team already exceeds, e.g. after dropping to Free with more projects than Free allows.
     * Nothing is deleted or locked in that case; only adding more is blocked (see check()).
     * Members counts real members only: a pending invitation is not over the limit, it just cannot be accepted yet.
     *
     * @return list<array{key: string, label: string, used: int, limit: int}>
     */
    public function overages(Team $team): array
    {
        $plan = $team->currentPlan();
        $overages = [];

        foreach (['projects', 'members'] as $key) {
            $limit = $plan->limit($key);
            $used = $key === 'members' ? $team->users()->count() : $this->usage($team, $key);

            if ($limit !== null && $used > $limit) {
                $overages[] = ['key' => $key, 'label' => $key, 'used' => $used, 'limit' => $limit];
            }
        }

        return $overages;
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
        $hint = $next ? __('Upgrade to :plan to add more.', ['plan' => $next->label()]) : __('Contact us for a custom plan.');

        return Response::deny(__('Your :plan plan includes up to :limit :noun. :hint', ['plan' => $plan->label(), 'limit' => $limit, 'noun' => __($noun), 'hint' => $hint]))->withStatus(402);
    }
}
