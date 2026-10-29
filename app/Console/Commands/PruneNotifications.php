<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;

class PruneNotifications extends Command
{
    protected $signature = 'notifications:prune';

    protected $description = 'Delete in-app notifications older than the retention period (read or not)';

    public function handle(): int
    {
        $days = (int) config('retention.notifications_days');

        // Like the audit log, a retention of 0 keeps them forever.
        if ($days > 0) {
            $deleted = DatabaseNotification::where('created_at', '<', now()->subDays($days))->delete();
            $this->info("Deleted {$deleted} notification(s) older than {$days} days.");
        }

        return self::SUCCESS;
    }
}
