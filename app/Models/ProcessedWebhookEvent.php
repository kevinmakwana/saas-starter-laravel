<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $event_id
 * @property string $type
 * @property array<string, mixed> $payload
 * @property Carbon|null $processed_at
 */
class ProcessedWebhookEvent extends Model
{
    use MassPrunable;

    /** @var list<string> */
    protected $fillable = ['event_id', 'type', 'payload', 'processed_at'];

    /**
     * Only processed events are pruned: one with no `processed_at` still needs to be retried.
     *
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return static::where('processed_at', '<', now()->subDays(config('retention.webhook_events_days')));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['payload' => 'array', 'processed_at' => 'datetime'];
    }
}
