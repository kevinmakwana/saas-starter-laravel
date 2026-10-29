<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\Project;
use App\Models\Task;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function store(Request $request, Project $project, Task $task): RedirectResponse
    {
        Gate::authorize('manage', $task);

        $extensions = implode(',', config('attachments.extensions'));
        $request->validate([
            // `extensions` checks the name, `mimes` what the file really is: a page named photo.png is refused.
            'file' => ['required', 'file', 'max:'.config('attachments.max_file_kb'), "extensions:{$extensions}", "mimes:{$extensions}"],
        ]);

        /** @var UploadedFile $file */
        $file = $request->file('file');
        Gate::authorize('upload', [Attachment::class, (int) $file->getSize()]);

        // The stored name is random, so nothing the user typed ever reaches the file system.
        $path = $file->storeAs('attachments/'.$this->team($request)->getKey(), (string) Str::uuid(), config('attachments.disk'));

        $attachment = $task->attachments()->create([
            'uploaded_by' => $this->user($request)->getKey(),
            'name' => $this->displayName($file),
            'path' => $path,
            'mime' => $file->getMimeType() ?? 'application/octet-stream',
            'size' => (int) $file->getSize(),
        ]);
        $this->audit->record('attachment.added', "Attached “{$attachment->name}” to task “{$task->title}”", $attachment);

        return back()->with('success', 'File attached.');
    }

    /** Always a download, never shown inline, and only to a member of the team that owns it. */
    public function download(Attachment $attachment): StreamedResponse
    {
        Gate::authorize('manage', $attachment);

        $disk = Storage::disk(config('attachments.disk'));
        abort_unless($disk->exists($attachment->path), 404);

        return $disk->download($attachment->path, $attachment->name, ['Content-Type' => $attachment->mime]);
    }

    public function destroy(Attachment $attachment): RedirectResponse
    {
        Gate::authorize('manage', $attachment);

        $attachment->delete();
        $this->audit->record('attachment.deleted', "Removed “{$attachment->name}” from a task");

        return back()->with('success', 'File removed.');
    }

    /** The name as the user sees it: no folders, control characters or markup, and at most 255 characters. */
    private function displayName(UploadedFile $file): string
    {
        $name = basename(str_replace('\\', '/', $file->getClientOriginalName()));
        $name = preg_replace('/[\x00-\x1F\x7F<>:"|?*]+/u', '', $name) ?? '';
        $name = ltrim(trim($name), '.');

        if (mb_strlen($name) > 255) {
            $extension = pathinfo($name, PATHINFO_EXTENSION);
            $name = mb_substr(pathinfo($name, PATHINFO_FILENAME), 0, 254 - mb_strlen($extension)).'.'.$extension;
        }

        return $name === '' ? 'file' : $name;
    }
}
