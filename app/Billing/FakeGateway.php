<?php

namespace App\Billing;

use App\Enums\Plan;
use App\Models\Team;

/** Applies plan changes locally so the app runs end to end without Stripe keys. */
class FakeGateway implements BillingGateway
{
    public function subscribe(Team $team, Plan $plan): ?string
    {
        $this->apply($team, $plan);

        return null;
    }

    public function changePlan(Team $team, Plan $plan): void
    {
        $this->apply($team, $plan);
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
        $this->apply($team, Plan::Free);
    }

    private function apply(Team $team, Plan $plan): void
    {
        $team->forceFill(['plan' => $plan->value, 'plan_ends_at' => null])->save();
    }
}
