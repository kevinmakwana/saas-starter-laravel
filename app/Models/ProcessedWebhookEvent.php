<?php

namespace App\Models;

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
    /** @var list<string> */
    protected $fillable = ['event_id', 'type', 'payload', 'processed_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['payload' => 'array', 'processed_at' => 'datetime'];
    }
}
