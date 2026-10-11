<?php

use App\Enums\Role;
use App\Models\Project;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;

beforeEach(function () {
    $this->user = ownerWithTeam('business');
    $this->team = $this->user->currentTeam;
});

it('creates, shows, updates and deletes a project', function () {
    $this->actingAs($this->user)->post('/projects', ['name' => 'Launch', 'description' => 'Ship it'])
        ->assertRedirect();
    $project = asTenant($this->team, fn () => Project::firstWhere('name', 'Launch'));

    expect($project->tenant_id)->toBe($this->team->id);

    $this->actingAs($this->user)->get("/projects/{$project->id}")->assertOk()
        ->assertInertia(fn ($page) => $page->component('Projects/Show')->where('project.name', 'Launch'));
    $this->actingAs($this->user)->put("/projects/{$project->id}", ['name' => 'Launch v2'])->assertRedirect();
    expect(asTenant($this->team, fn () => $project->fresh()->name))->toBe('Launch v2');

    $this->actingAs($this->user)->delete("/projects/{$project->id}")->assertRedirect(route('projects.index'));
    expect(asTenant($this->team, fn () => Project::count()))->toBe(0);
});

it('validates input', function () {
    $this->actingAs($this->user)->post('/projects', ['name' => ''])->assertSessionHasErrors('name');
});

it('searches and paginates projects', function () {
    asTenant($this->team, function () {
        Project::factory()->count(12)->sequence(fn ($s) => ['name' => 'Alpha '.$s->index])->create();
        Project::factory()->create(['name' => 'Zebra crossing']);
    });

    $this->actingAs($this->user)->get('/projects')->assertInertia(fn ($page) => $page
        ->has('projects.data', 10)->where('projects.total', 13)->where('projects.last_page', 2));

    $this->actingAs($this->user)->get('/projects?q=zebra')->assertInertia(fn ($page) => $page
        ->has('projects.data', 1)->where('projects.data.0.name', 'Zebra crossing')->where('filters.q', 'zebra'));
});

it('treats LIKE wildcards in search literally', function () {
    asTenant($this->team, fn () => Project::factory()->create(['name' => 'Plain']));

    $this->actingAs($this->user)->get('/projects?q=%25')->assertInertia(fn ($page) => $page->has('projects.data', 0));
});

it('returns 404 for another tenants project on every route', function () {
    $other = Team::factory()->create(['plan' => 'business']);
    $foreign = asTenant($other, fn () => Project::factory()->create());

    $this->actingAs($this->user)->get("/projects/{$foreign->id}")->assertNotFound();
    $this->actingAs($this->user)->get("/projects/{$foreign->id}/edit")->assertNotFound();
    $this->actingAs($this->user)->put("/projects/{$foreign->id}", ['name' => 'x'])->assertNotFound();
    $this->actingAs($this->user)->delete("/projects/{$foreign->id}")->assertNotFound();
    $this->actingAs($this->user)->post("/projects/{$foreign->id}/tasks", ['title' => 'x'])->assertNotFound();

    expect(asTenant($other, fn () => Project::find($foreign->id)))->not->toBeNull();
});

it('never leaks other tenants projects in listings', function () {
    $other = Team::factory()->create();
    asTenant($other, fn () => Project::factory()->create(['name' => 'Secret']));

    $this->actingAs($this->user)->get('/projects')->assertInertia(fn ($page) => $page->has('projects.data', 0));
});

it('enforces the project limit with an upgrade prompt', function () {
    $this->team->forceFill(['plan' => 'free'])->save();
    asTenant($this->team, fn () => Project::factory()->count(3)->create());

    $this->actingAs($this->user)->post('/projects', ['name' => 'Fourth'])
        ->assertRedirect()
        ->assertSessionHas('upgrade', fn ($m) => str_contains($m, 'Free plan includes up to 3 projects') && str_contains($m, 'Upgrade to Pro'));

    expect(asTenant($this->team, fn () => Project::count()))->toBe(3);

    $this->team->forceFill(['plan' => 'pro'])->save();
    $this->actingAs($this->user)->post('/projects', ['name' => 'Fourth'])->assertSessionMissing('upgrade');
    expect(asTenant($this->team, fn () => Project::count()))->toBe(4);
});

it('only lets owners and admins delete projects', function () {
    $member = User::factory()->create(['current_team_id' => $this->team->id]);
    $this->team->addMember($member, Role::Member);
    $project = asTenant($this->team, fn () => Project::factory()->create());

    $this->actingAs($member)->delete("/projects/{$project->id}")->assertForbidden();
    $this->actingAs($member)->put("/projects/{$project->id}", ['name' => 'Renamed by member'])->assertRedirect();
    expect(asTenant($this->team, fn () => Project::count()))->toBe(1);
});

it('manages tasks within a project', function () {
    $project = asTenant($this->team, fn () => Project::factory()->create());

    $this->actingAs($this->user)->post("/projects/{$project->id}/tasks", ['title' => 'Write docs'])->assertRedirect();
    $task = asTenant($this->team, fn () => Task::firstWhere('title', 'Write docs'));
    expect($task->status)->toBe('todo')->and($task->tenant_id)->toBe($this->team->id);

    $this->actingAs($this->user)->put("/projects/{$project->id}/tasks/{$task->id}", ['title' => 'Write docs', 'status' => 'done'])->assertRedirect();
    expect(asTenant($this->team, fn () => $task->fresh()->status))->toBe('done');

    $this->actingAs($this->user)->put("/projects/{$project->id}/tasks/{$task->id}", ['title' => 'x', 'status' => 'bogus'])->assertSessionHasErrors('status');

    $this->actingAs($this->user)->get("/projects/{$project->id}?status=done&q=docs")
        ->assertInertia(fn ($page) => $page->has('tasks.data', 1));
    $this->actingAs($this->user)->get("/projects/{$project->id}?status=todo")
        ->assertInertia(fn ($page) => $page->has('tasks.data', 0));

    $this->actingAs($this->user)->delete("/projects/{$project->id}/tasks/{$task->id}")->assertRedirect();
    expect(asTenant($this->team, fn () => Task::count()))->toBe(0);
});

it('does not resolve a task through the wrong project or tenant', function () {
    $project = asTenant($this->team, fn () => Project::factory()->create());
    $sibling = asTenant($this->team, fn () => Project::factory()->create());
    $task = asTenant($this->team, fn () => Task::factory()->create(['project_id' => $sibling->id]));

    $this->actingAs($this->user)->delete("/projects/{$project->id}/tasks/{$task->id}")->assertNotFound();

    $other = Team::factory()->create();
    $foreignProject = asTenant($other, fn () => Project::factory()->create());
    $foreignTask = asTenant($other, fn () => Task::factory()->create(['project_id' => $foreignProject->id]));
    $this->actingAs($this->user)->delete("/projects/{$project->id}/tasks/{$foreignTask->id}")->assertNotFound();
});

it('requires login', function () {
    $this->get('/projects')->assertRedirect('/login');
});
