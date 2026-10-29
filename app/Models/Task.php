<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use BelongsToTenant, HasFactory;

    public const STATUSES = ['todo', 'doing', 'done'];

    /** @var list<string> */
    protected $fillable = ['title', 'status'];

    protected static function booted(): void
    {
        // One at a time, so each file is deleted from storage too.
        static::deleting(fn (Task $task) => $task->attachments->each->delete());
    }

    /** @return HasMany<Attachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
