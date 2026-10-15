<?php

namespace App\Console\Commands;

use App\Jobs\ProcessStripeWebhook;
use App\Models\ProcessedWebhookEvent;
use Illuminate\Console\Command;

class ReplayWebhook extends Command
{
    protected $signature = 'webhooks:replay {event_id : The Stripe event id, e.g. evt_123}';

    protected $description = 'Process a stored Stripe webhook event again, even if it was handled before';

    public function handle(): int
    {
        $record = ProcessedWebhookEvent::firstWhere('event_id', $this->argument('event_id'));

        if ($record === null) {
            $this->error("No stored webhook event with id {$this->argument('event_id')}.");

            return self::FAILURE;
        }

        $record->forceFill(['processed_at' => null])->save();
        ProcessStripeWebhook::dispatch($record->id);

        $this->info("Replayed {$record->type} ({$record->event_id}).");

        return self::SUCCESS;
    }
}
