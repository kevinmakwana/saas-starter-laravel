<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use BelongsToTenant, HasFactory;

    public const STATUSES = ['todo', 'doing', 'done'];

    /** @var list<string> */
    protected $fillable = ['title', 'status'];

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
