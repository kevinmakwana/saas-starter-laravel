<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Project::class);

        $search = trim((string) $request->query('q', ''));

        $projects = Project::query()
            ->withCount('tasks')
            ->when($search !== '', fn ($query) => $query->where('name', 'like', '%'.addcslashes($search, '%_\\').'%'))
            ->orderBy('name')
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('Projects/Index', [
            'projects' => $projects,
            'filters' => ['q' => $search],
            'can' => ['create' => Gate::allows('create', Project::class)],
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Project::class);

        return Inertia::render('Projects/Form', ['project' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Project::class);

        $project = Project::create($this->validated($request));
        $this->audit->record('project.created', "Created project “{$project->name}”", $project);

        return redirect()->route('projects.show', $project)->with('success', 'Project created.');
    }

    public function show(Request $request, Project $project): Response
    {
        Gate::authorize('view', $project);

        $search = trim((string) $request->query('q', ''));
        $status = $request->query('status');

        $tasks = $project->tasks()
            ->when($search !== '', fn ($query) => $query->where('title', 'like', '%'.addcslashes($search, '%_\\').'%'))
            ->when(in_array($status, \App\Models\Task::STATUSES, true), fn ($query) => $query->where('status', $status))
            ->latest('id')
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('Projects/Show', [
            'project' => $project->only(['id', 'name', 'description']),
            'tasks' => $tasks,
            'filters' => ['q' => $search, 'status' => is_string($status) ? $status : ''],
            'statuses' => \App\Models\Task::STATUSES,
            'can' => ['delete' => Gate::allows('delete', $project)],
        ]);
    }

    public function edit(Project $project): Response
    {
        Gate::authorize('update', $project);

        return Inertia::render('Projects/Form', ['project' => $project->only(['id', 'name', 'description'])]);
    }

    public function update(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('update', $project);

        $project->update($this->validated($request));
        $this->audit->record('project.updated', "Updated project “{$project->name}”", $project);

        return redirect()->route('projects.show', $project)->with('success', 'Project updated.');
    }

    public function destroy(Project $project): RedirectResponse
    {
        Gate::authorize('delete', $project);

        $project->delete();
        $this->audit->record('project.deleted', "Deleted project “{$project->name}”", $project);

        return redirect()->route('projects.index')->with('success', 'Project deleted.');
    }

    /** @return array{name: string, description: string|null} */
    private function validated(Request $request): array
    {
        /** @var array{name: string, description: string|null} */
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
