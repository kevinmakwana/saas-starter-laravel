<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\TaskResource;
use App\Models\Project;
use App\Models\Task;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Tasks of a project of the token's team. */
class TaskController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        Gate::authorize('view', $project);
        $status = $request->query('status');

        return TaskResource::collection(
            $project->tasks()
                ->when(in_array($status, Task::STATUSES, true), fn ($query) => $query->where('status', $status))
                ->latest('id')
                ->paginate(25)
                ->withQueryString(),
        );
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('update', $project);

        /** @var array{title: string, status?: string} $data */
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(Task::STATUSES)],
        ]);
        $task = $project->tasks()->create($data + ['status' => 'todo']);
        $this->audit->record('task.created', "Added task “{$task->title}” to “{$project->name}” (via API)", $task);

        return (new TaskResource($task))->response()->setStatusCode(201);
    }

    public function update(Request $request, Project $project, Task $task): TaskResource
    {
        Gate::authorize('manage', $task);

        $task->update($request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(Task::STATUSES)],
        ]));
        $this->audit->record('task.updated', "Updated task “{$task->title}” ({$task->status}) (via API)", $task);

        return new TaskResource($task);
    }

    public function destroy(Project $project, Task $task): Response
    {
        Gate::authorize('manage', $task);

        $task->delete();
        $this->audit->record('task.deleted', "Deleted task “{$task->title}” (via API)", $task);

        return response()->noContent();
    }
}
