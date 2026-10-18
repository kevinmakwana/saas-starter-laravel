<?php

namespace App\Billing;

use App\Enums\Plan;
use App\Models\Team;
use Laravel\Cashier\Invoice;
use RuntimeException;

/** Cashier 16 backed gateway. The `plan` column is then kept in sync by the webhook job. */
class StripeGateway implements BillingGateway
{
    public function subscribe(Team $team, Plan $plan): ?string
    {
        $checkout = $team->newSubscription('default', $this->price($plan))
            ->checkout([
                'success_url' => route('billing.show', ['checkout' => 'success']),
                'cancel_url' => route('billing.show', ['checkout' => 'cancelled']),
            ]);

        return $checkout->asStripeCheckoutSession()->url;
    }

    public function changePlan(Team $team, Plan $plan): void
    {
        $team->subscription('default')?->swap($this->price($plan))
            ?? throw new RuntimeException('Team has no active subscription.');
        $team->forceFill(['plan' => $plan->value, 'plan_ends_at' => null])->save();
    }

    public function cancel(Team $team): void
    {
        $subscription = $team->subscription('default')?->cancel();
        $team->forceFill(['plan_ends_at' => $subscription?->ends_at])->save();
    }

    public function resume(Team $team): void
    {
        $team->subscription('default')?->resume();
        $team->forceFill(['plan_ends_at' => null])->save();
    }

    public function terminate(Team $team): void
    {
        $team->subscription('default')?->cancelNow();
    }

    public function supportsPortal(): bool
    {
        return true;
    }

    public function portalUrl(Team $team, string $returnUrl): string
    {
        return $team->billingPortalUrl($returnUrl);
    }

    public function invoices(Team $team): array
    {
        if (! $team->hasStripeId()) {
            return [];
        }

        // Includes open (unpaid) invoices, which matter after a failed payment; drafts are Stripe-internal.
        return $team->invoicesIncludingPending()
            ->reject(fn (Invoice $invoice) => $invoice->asStripeInvoice()->status === 'draft')
            ->map(fn (Invoice $invoice) => InvoiceSummary::fromCashier($invoice))
            ->values()
            ->all();
    }

    private function price(Plan $plan): string
    {
        return $plan->stripePrice() ?? throw new RuntimeException("No Stripe price configured for {$plan->label()}.");
    }
}
