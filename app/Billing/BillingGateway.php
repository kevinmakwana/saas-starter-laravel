<?php

namespace App\Billing;

use App\Enums\Plan;
use App\Models\Team;

interface BillingGateway
{
    /**
     * Start a subscription. Returns a URL to redirect the owner to (e.g. Stripe Checkout),
     * or null when the plan was applied immediately.
     */
    public function subscribe(Team $team, Plan $plan): ?string;

    public function changePlan(Team $team, Plan $plan): void;

    /** Cancel at the end of the paid period. */
    public function cancel(Team $team): void;

    public function resume(Team $team): void;
}
