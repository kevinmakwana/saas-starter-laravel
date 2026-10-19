<?php

namespace App\Billing;

use Laravel\Cashier\Http\Controllers\WebhookController;

/**
 * Cashier keeps its `subscriptions` records current by handling Stripe's subscription events. The app verifies and
 * queues those events itself (see StripeWebhookController), so this reuses Cashier's own handlers from the queued job
 * instead of exposing Cashier's separate webhook route. Without these records `changePlan`, `cancel`, `resume`
 * and `terminate` would find no subscription to act on.
 */
class SubscriptionRecords extends WebhookController
{
    /** @param  array<string, mixed>  $event  a decoded Stripe event */
    public function apply(array $event): void
    {
        // For a cancellation at period end Cashier asks the Stripe API when the period ends. Stripe already sends that
        // moment as `cancel_at`, which Cashier reads without a network call, so queued processing needs no Stripe access.
        if (($event['data']['object']['cancel_at_period_end'] ?? false) && isset($event['data']['object']['cancel_at'])) {
            $event['data']['object']['cancel_at_period_end'] = false;
        }

        match ($event['type'] ?? null) {
            'customer.subscription.created' => $this->handleCustomerSubscriptionCreated($event),
            'customer.subscription.updated' => $this->handleCustomerSubscriptionUpdated($event),
            'customer.subscription.deleted' => $this->handleCustomerSubscriptionDeleted($event),
            default => null,
        };
    }
}
