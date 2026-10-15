<?php

namespace App\Console\Commands;

use App\Jobs\ProcessStripeWebhook;
use App\Models\ProcessedWebhookEvent;
use Illuminate\Console\Command;

class RetryStuckWebhooks extends Command
{
    protected $signature = 'webhooks:retry-stuck';

    protected $description = 'Re-dispatch Stripe webhook events that were stored but never processed';

    public function handle(): int
    {
        $stuck = ProcessedWebhookEvent::whereNull('processed_at')
            ->where('created_at', '<', now()->subMinutes((int) config('billing.webhook.retry_after_minutes')))
            ->where('created_at', '>', now()->subDays((int) config('billing.webhook.retry_window_days')))
            ->get();

        foreach ($stuck as $record) {
            ProcessStripeWebhook::dispatch($record->id);
        }

        $this->info("Re-dispatched {$stuck->count()} stuck webhook event(s).");

        return self::SUCCESS;
    }
}
