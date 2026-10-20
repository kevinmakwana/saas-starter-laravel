<?php

namespace App\Billing;

use App\Enums\BillingInterval;
use App\Enums\Plan;
use App\Models\Team;
use LogicException;

/** Applies plan changes locally so the app runs end to end without Stripe keys. */
class FakeGateway implements BillingGateway
{
    public function subscribe(Team $team, Plan $plan, BillingInterval $interval = BillingInterval::Monthly): ?string
    {
        $this->apply($team, $plan);

        return null;
    }

    public function changePlan(Team $team, Plan $plan, BillingInterval $interval = BillingInterval::Monthly): void
    {
        $this->apply($team, $plan);
    }

    // The fake gateway applies plans instantly and has no billing periods, so it models neither trials nor yearly billing.
    public function trialDays(Team $team): int
    {
        return 0;
    }

    public function offersYearly(Plan $plan): bool
    {
        return false;
    }

    public function cancel(Team $team): void
    {
        // Keep access until the end of the (fake) 30 day period, then drop to Free.
        $team->forceFill(['plan_ends_at' => now()->addDays(30)])->save();
    }

    public function resume(Team $team): void
    {
        $team->forceFill(['plan_ends_at' => null])->save();
    }

    public function terminate(Team $team): void
    {
        // Quietly: the team is about to be deleted, so its owner must not be told it is over its limits.
        $team->forceFill(['plan' => Plan::Free->value, 'plan_ends_at' => null])->saveQuietly();
    }

    public function supportsPortal(): bool
    {
        return false;
    }

    public function portalUrl(Team $team, string $returnUrl): string
    {
        throw new LogicException('The fake gateway has no billing portal.');
    }

    public function invoices(Team $team): array
    {
        return [];
    }

    private function apply(Team $team, Plan $plan): void
    {
        $team->forceFill(['plan' => $plan->value, 'plan_ends_at' => null])->save();
    }
}
