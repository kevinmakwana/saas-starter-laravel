<?php

namespace App\Billing;

use App\Enums\BillingInterval;
use App\Enums\Plan;
use App\Models\Team;
use Laravel\Cashier\Invoice;
use RuntimeException;

/** Cashier 16 backed gateway. The `plan` column is then kept in sync by the webhook job. */
class StripeGateway implements BillingGateway
{
    public function subscribe(Team $team, Plan $plan, BillingInterval $interval = BillingInterval::Monthly): ?string
    {
        // A second Checkout would bill the team twice. This also covers a team whose plan dropped to Free
        // because a payment failed: its Stripe subscription is still there, and the portal is how to fix it.
        $existing = $team->subscription('default');
        if ($existing !== null && ! $existing->ended() && in_array($existing->stripe_status, ['active', 'trialing', 'past_due'], true)) {
            throw new AlreadySubscribed('Your team already has a subscription. Use Switch plan, Resume, or Update payment method instead.');
        }

        $builder = $team->newSubscription('default', $this->price($plan, $interval));
        if (($days = $this->trialDays($team)) > 0) {
            $builder->trialDays($days);
        }

        $checkout = $builder->checkout([
            'success_url' => route('billing.show', ['checkout' => 'success']),
            'cancel_url' => route('billing.show', ['checkout' => 'cancelled']),
        ]);

        return $checkout->asStripeCheckoutSession()->url;
    }

    /** A trial is a one-time offer, so any earlier subscription (even a cancelled one) uses it up. */
    public function trialDays(Team $team): int
    {
        return $team->subscriptions()->exists() ? 0 : max(0, (int) config('billing.trial_days'));
    }

    public function offersYearly(Plan $plan): bool
    {
        return $plan->hasYearlyPrice() && $plan->stripePrice(BillingInterval::Yearly) !== null;
    }

    public function changePlan(Team $team, Plan $plan, BillingInterval $interval = BillingInterval::Monthly): void
    {
        // swap() keeps a running trial, so switching plans never skips it or charges early.
        $team->subscription('default')?->swap($this->price($plan, $interval))
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

    private function price(Plan $plan, BillingInterval $interval): string
    {
        return $plan->stripePrice($interval)
            ?? throw new RuntimeException("No Stripe {$interval->value} price configured for {$plan->label()}.");
    }
}
