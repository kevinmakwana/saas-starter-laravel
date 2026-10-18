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

    /** End billing immediately because the team is being deleted. No access period is kept. */
    public function terminate(Team $team): void;

    /** Whether customers can manage their payment method and invoices at a hosted portal (Stripe's Customer Portal). */
    public function supportsPortal(): bool;

    /** A short-lived URL for the team's portal session; the customer returns to $returnUrl. Only call when supported. */
    public function portalUrl(Team $team, string $returnUrl): string;

    /**
     * Invoices, newest first. Empty when the team has none.
     *
     * @return list<InvoiceSummary>
     */
    public function invoices(Team $team): array;
}
