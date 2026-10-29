<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/** Projects of the token's team. The rules are the web app's: the same policies, plan limits and audit log. */
class ProjectController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Project::class);

        return ProjectResource::collection(Project::query()->withCount('tasks')->orderBy('name')->orderBy('id')->paginate(25));
    }

    public function show(Project $project): ProjectResource
    {
        Gate::authorize('view', $project);

        return new ProjectResource($project->loadCount('tasks'));
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', Project::class);

        $project = Project::create($request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]));
        $this->audit->record('project.created', "Created project “{$project->name}” (via API)", $project);

        return (new ProjectResource($project->loadCount('tasks')))->response()->setStatusCode(201);
    }

    public function update(Request $request, Project $project): ProjectResource
    {
        Gate::authorize('update', $project);

        $project->update($request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]));
        $this->audit->record('project.updated', "Updated project “{$project->name}” (via API)", $project);

        return new ProjectResource($project->loadCount('tasks'));
    }

    public function destroy(Project $project): Response
    {
        Gate::authorize('delete', $project);

        $project->delete();
        $this->audit->record('project.deleted', "Deleted project “{$project->name}” (via API)", $project);

        return response()->noContent();
    }
}
