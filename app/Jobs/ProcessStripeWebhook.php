<?php

namespace App\Jobs;

use App\Enums\Plan;
use App\Mail\PaymentFailedMail;
use App\Models\ProcessedWebhookEvent;
use App\Models\Team;
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
        $record = ProcessedWebhookEvent::findOrFail($this->recordId);

        if ($record->processed_at !== null) {
            return;
        }

        $object = $record->payload['data']['object'] ?? [];

        match ($record->type) {
            'customer.subscription.created' => $this->syncSubscription($object, announce: true),
            'customer.subscription.updated' => $this->syncSubscription($object),
            'customer.subscription.deleted' => $this->dropToFree($object),
            'invoice.payment_failed' => $this->paymentFailed($object),
            'invoice.paid', 'invoice.payment_succeeded' => $this->paymentRecovered($object),
            default => null,
        };

        $record->forceFill(['processed_at' => now()])->save();
    }

    /** @param  array<string, mixed>  $subscription */
    private function syncSubscription(array $subscription, bool $announce = false): void
    {
        $team = Team::where('stripe_id', $subscription['customer'] ?? null)->first();
        if ($team === null) {
            return;
        }

        $status = $subscription['status'] ?? '';
        if (in_array($status, ['canceled', 'incomplete_expired', 'unpaid'], true)) {
            $this->dropToFree($subscription);

            return;
        }

        $plan = Plan::fromStripePrice($subscription['items']['data'][0]['price']['id'] ?? null);
        if ($plan === null) {
            return;
        }

        $endsAt = ($subscription['cancel_at_period_end'] ?? false) && isset($subscription['current_period_end'])
            ? Carbon::createFromTimestamp($subscription['current_period_end'])
            : null;

        $team->forceFill(['plan' => $plan->value, 'plan_ends_at' => $endsAt])->save();

        // Checkout only counts once Stripe confirms the subscription, so this is where "started" is recorded.
        if ($announce) {
            app(TenantContext::class)->run($team, fn () => app(AuditLogger::class)
                ->record('billing.subscribed', "Started the {$plan->label()} plan"));
        }
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

    /** @param  array<string, mixed>  $subscription */
    private function dropToFree(array $subscription): void
    {
        Team::where('stripe_id', $subscription['customer'] ?? null)
            ->update(['plan' => Plan::Free->value, 'plan_ends_at' => null, 'payment_failed_at' => null, 'payment_grace_ends_at' => null]);
    }
}
