<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * A file attached to a task.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $task_id
 * @property int|null $uploaded_by
 * @property string $name
 * @property string $path
 * @property string $mime
 * @property int $size
 * @property Carbon $created_at
 */
class Attachment extends Model
{
    use BelongsToTenant;

    /** @var list<string> */
    protected $fillable = ['task_id', 'uploaded_by', 'name', 'path', 'mime', 'size'];

    protected static function booted(): void
    {
        // Deleting the row deletes the file. Rows that go with their task, project or team are covered by hooks on those models.
        static::deleted(fn (Attachment $attachment) => Storage::disk(config('attachments.disk'))->delete($attachment->path));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
