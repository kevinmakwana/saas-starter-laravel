<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use BelongsToTenant, HasFactory;

    /** @var list<string> */
    protected $fillable = ['name', 'description'];

    protected static function booted(): void
    {
        // The tasks go by a database cascade, which cannot delete files, so do the files first.
        static::deleting(fn (Project $project) => Attachment::whereIn('task_id', $project->tasks()->select('tasks.id'))->get()->each->delete());
    }

    /** @return HasMany<Task, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }
}
