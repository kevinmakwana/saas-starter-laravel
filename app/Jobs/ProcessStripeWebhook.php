<?php

namespace App\Jobs;

use App\Billing\SubscriptionRecords;
use App\Enums\Plan;
use App\Mail\PaymentFailedMail;
use App\Mail\TrialEndingMail;
use App\Models\ProcessedWebhookEvent;
use App\Models\Team;
use App\Notifications\PaymentFailed;
use App\Notifications\TrialEnding;
use App\Services\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** Unique per stored event so a Stripe redelivery or the retry sweeper cannot run the same event twice at once. */
class ProcessStripeWebhook implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** Frees the unique lock even if a worker dies mid-job. */
    public int $uniqueFor = 600;

    public function __construct(public int $recordId)
    {
        $this->onQueue('webhooks');
    }

    public function uniqueId(): string
    {
        return (string) $this->recordId;
    }

    /** The event stays unprocessed, so `webhooks:retry-stuck` picks it up again. */
    public function failed(Throwable $exception): void
    {
        Log::error('Stripe webhook processing failed', ['record_id' => $this->recordId, 'exception' => $exception]);
    }

    public function handle(): void
    {
        // Pruned or removed since it was queued: nothing left to do, and retrying would not bring it back.
        $record = ProcessedWebhookEvent::find($this->recordId);

        if ($record === null || $record->processed_at !== null) {
            return;
        }

        $object = $record->payload['data']['object'] ?? [];

        match ($record->type) {
            'customer.subscription.created',
            'customer.subscription.updated',
            'customer.subscription.deleted' => $this->subscriptionEvent($record, $object),
            'customer.subscription.trial_will_end' => $this->trialEnding($object),
            'invoice.payment_failed' => $this->paymentFailed($object),
            'invoice.paid', 'invoice.payment_succeeded' => $this->paymentRecovered($object),
            default => null,
        };

        $record->forceFill(['processed_at' => now()])->save();
    }

    /** @param  array<string, mixed>  $subscription */
    private function subscriptionEvent(ProcessedWebhookEvent $record, array $subscription): void
    {
        $team = Team::where('stripe_id', $subscription['customer'] ?? null)->first();
        if ($team === null) {
            return;
        }

        // Stripe does not guarantee order: an older update arriving after a newer event must not undo it.
        $createdAt = isset($record->payload['created']) ? Carbon::createFromTimestamp((int) $record->payload['created']) : null;
        if ($createdAt !== null && $team->stripe_event_at !== null && $createdAt->lt($team->stripe_event_at)) {
            return;
        }
        if ($createdAt !== null) {
            $team->forceFill(['stripe_event_at' => $createdAt]);
        }

        app(SubscriptionRecords::class)->apply($record->payload);

        if ($record->type === 'customer.subscription.deleted') {
            $this->dropToFree($team);

            return;
        }

        // The first update after an incomplete payment is when the plan really starts.
        $announce = $record->type === 'customer.subscription.created'
            || ($record->payload['data']['previous_attributes']['status'] ?? null) === 'incomplete';

        $this->syncSubscription($team, $subscription, $announce);
    }

    /** @param  array<string, mixed>  $subscription */
    private function syncSubscription(Team $team, array $subscription, bool $announce): void
    {
        $status = $subscription['status'] ?? '';

        if (in_array($status, ['canceled', 'incomplete_expired', 'unpaid'], true)) {
            $this->dropToFree($team);

            return;
        }

        // The first payment has not gone through (yet): no plan until Stripe reports the subscription as active.
        if ($status === 'incomplete') {
            $team->save();

            return;
        }

        $price = $subscription['items']['data'][0]['price']['id'] ?? null;
        $plan = Plan::fromStripePrice($price);
        if ($plan === null) {
            Log::warning('Stripe subscription uses an unknown price; the plan was not changed', ['team_id' => $team->getKey(), 'price' => $price]);
            $team->save();

            return;
        }

        $endsAt = ($subscription['cancel_at_period_end'] ?? false) ? $this->periodEnd($subscription) : null;

        $team->forceFill(['plan' => $plan->value, 'plan_ends_at' => $endsAt])->save();

        // Checkout only counts once Stripe confirms the subscription, so this is where "started" is recorded.
        if ($announce) {
            app(TenantContext::class)->run($team, fn () => app(AuditLogger::class)
                ->record('billing.subscribed', "Started the {$plan->label()} plan"));
        }
    }

    /**
     * When the paid period ends. Newer Stripe API versions report it per subscription item instead of on the
     * subscription, and always send it as `cancel_at` once a cancellation at period end is scheduled.
     *
     * @param  array<string, mixed>  $subscription
     */
    private function periodEnd(array $subscription): ?Carbon
    {
        $itemEnds = array_filter(array_column($subscription['items']['data'] ?? [], 'current_period_end'));
        $timestamp = $subscription['cancel_at'] ?? $subscription['current_period_end'] ?? ($itemEnds === [] ? null : max($itemEnds));

        return $timestamp === null ? null : Carbon::createFromTimestamp((int) $timestamp);
    }

    /**
     * Stripe sends this three days before a trial ends. The first charge follows, so the owner gets a heads-up,
     * unless the subscription was already set to cancel (nothing will be charged) or is no longer trialing.
     *
     * @param  array<string, mixed>  $subscription
     */
    private function trialEnding(array $subscription): void
    {
        $team = Team::where('stripe_id', $subscription['customer'] ?? null)->first();

        if ($team === null
            || ($subscription['status'] ?? null) !== 'trialing'
            || ($subscription['cancel_at_period_end'] ?? false)
            || ! isset($subscription['trial_end'])
            || $team->owner === null) {
            return;
        }

        $endsAt = Carbon::createFromTimestamp((int) $subscription['trial_end']);
        Mail::to($team->owner)->send(new TrialEndingMail($team, $endsAt));
        $team->owner->notify(new TrialEnding($team->getKey(), $team->name, $endsAt->toDateString()));
    }

    /** @param  array<string, mixed>  $invoice */
    private function paymentFailed(array $invoice): void
    {
        $team = Team::where('stripe_id', $invoice['customer'] ?? null)->first();

        // Stripe retries failed payments and sends an event each time: only the first one starts the clock.
        if ($team === null || $team->payment_failed_at !== null) {
            return;
        }

        $team->forceFill([
            'payment_failed_at' => now(),
            'payment_grace_ends_at' => now()->addDays((int) config('billing.grace_days')),
        ])->save();

        app(TenantContext::class)->run($team, fn () => app(AuditLogger::class)
            ->record('billing.payment_failed', 'A payment failed. Update the payment method before the grace period ends.'));

        if ($team->owner !== null) {
            Mail::to($team->owner)->send(new PaymentFailedMail($team));
            $team->owner->notify(new PaymentFailed($team->getKey(), $team->name));
        }
    }

    /** @param  array<string, mixed>  $invoice */
    private function paymentRecovered(array $invoice): void
    {
        $team = Team::where('stripe_id', $invoice['customer'] ?? null)->first();

        if ($team === null || $team->payment_failed_at === null) {
            return;
        }

        $team->forceFill(['payment_failed_at' => null, 'payment_grace_ends_at' => null])->save();

        app(TenantContext::class)->run($team, fn () => app(AuditLogger::class)
            ->record('billing.payment_recovered', 'Payment received. The plan is fully active again.'));
    }

    private function dropToFree(Team $team): void
    {
        $team->forceFill([
            'plan' => Plan::Free->value, 'plan_ends_at' => null, 'payment_failed_at' => null, 'payment_grace_ends_at' => null,
        ])->save();
    }
}
