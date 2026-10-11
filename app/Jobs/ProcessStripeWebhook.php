<?php

namespace App\Jobs;

use App\Enums\Plan;
use App\Models\ProcessedWebhookEvent;
use App\Models\Team;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class ProcessStripeWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public int $recordId)
    {
        $this->onQueue('webhooks');
    }

    public function handle(): void
    {
        $record = ProcessedWebhookEvent::findOrFail($this->recordId);

        if ($record->processed_at !== null) {
            return;
        }

        $object = $record->payload['data']['object'] ?? [];

        match ($record->type) {
            'customer.subscription.created', 'customer.subscription.updated' => $this->syncSubscription($object),
            'customer.subscription.deleted' => $this->dropToFree($object),
            default => null,
        };

        $record->forceFill(['processed_at' => now()])->save();
    }

    /** @param  array<string, mixed>  $subscription */
    private function syncSubscription(array $subscription): void
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
    }

    /** @param  array<string, mixed>  $subscription */
    private function dropToFree(array $subscription): void
    {
        Team::where('stripe_id', $subscription['customer'] ?? null)
            ->update(['plan' => Plan::Free->value, 'plan_ends_at' => null]);
    }
}
