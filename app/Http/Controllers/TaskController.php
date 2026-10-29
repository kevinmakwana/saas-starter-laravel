<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Task;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class TaskController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function store(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('update', $project);

        $task = $project->tasks()->create($this->validated($request));
        $this->audit->record('task.created', "Added task “{$task->title}” to “{$project->name}”", $task);

        return back()->with('success', __('Task added.'));
    }

    public function update(Request $request, Project $project, Task $task): RedirectResponse
    {
        Gate::authorize('manage', $task);

        $task->update($this->validated($request));
        $this->audit->record('task.updated', "Updated task “{$task->title}” ({$task->status})", $task);

        return back()->with('success', __('Task updated.'));
    }

    public function destroy(Project $project, Task $task): RedirectResponse
    {
        Gate::authorize('manage', $task);

        $task->delete();
        $this->audit->record('task.deleted', "Deleted task “{$task->title}”", $task);

        return back()->with('success', __('Task deleted.'));
    }

    /** @return array{title: string, status: string} */
    private function validated(Request $request): array
    {
        /** @var array{title: string, status?: string} $data */
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(Task::STATUSES)],
        ]);

        return $data + ['status' => 'todo'];
    }
}
