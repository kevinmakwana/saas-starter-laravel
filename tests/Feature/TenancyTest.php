<?php

use App\Exceptions\TenancyException;
use App\Models\Project;
use App\Models\Task;
use App\Models\Team;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->teamA = Team::factory()->create();
    $this->teamB = Team::factory()->create();
    $this->projectA = asTenant($this->teamA, fn () => Project::factory()->create());
    $this->projectB = asTenant($this->teamB, fn () => Project::factory()->create());
});

it('only reads rows of the current tenant', function () {
    asTenant($this->teamA, function () {
        expect(Project::pluck('id')->all())->toBe([$this->projectA->id]);
        expect(Project::find($this->projectB->id))->toBeNull();
    });
});

it('fails closed when there is no tenant', function () {
    expect(Project::count())->toBe(0);
    expect(fn () => Project::factory()->create())->toThrow(TenancyException::class);
});

it('stamps tenant_id on create', function () {
    $project = asTenant($this->teamA, fn () => Project::create(['name' => 'Roadmap']));

    expect($project->tenant_id)->toBe($this->teamA->id);
});

it('refuses to create rows for another tenant', function () {
    asTenant($this->teamA, function () {
        $project = new Project(['name' => 'Sneaky']);
        $project->tenant_id = $this->teamB->id;

        expect(fn () => $project->save())->toThrow(TenancyException::class);
    });
});

it('refuses to move a row to another tenant', function () {
    asTenant($this->teamA, function () {
        $this->projectA->tenant_id = $this->teamB->id;

        expect(fn () => $this->projectA->save())->toThrow(TenancyException::class);
    });
});

it('cannot update or delete other tenants rows through queries or models', function () {
    $originalName = $this->projectB->name;

    asTenant($this->teamA, function () {
        expect(Project::whereKey($this->projectB->id)->update(['name' => 'hacked']))->toBe(0);
        expect(Project::whereKey($this->projectB->id)->delete())->toBe(0);
        expect(fn () => $this->projectB->delete())->toThrow(TenancyException::class);
        expect(fn () => $this->projectB->update(['name' => 'hacked']))->toThrow(TenancyException::class);
    });

    expect(Project::withoutGlobalScopes()->find($this->projectB->id)?->name)->toBe($originalName);
});

it('scopes child models and relations', function () {
    $task = asTenant($this->teamB, fn () => Task::factory()->create(['project_id' => $this->projectB->id]));

    asTenant($this->teamA, fn () => expect(Task::find($task->id))->toBeNull());
    asTenant($this->teamB, fn () => expect(Task::find($task->id))->not->toBeNull());
});

it('hides other tenants rows through route model binding', function () {
    Route::middleware(['web', 'auth'])->get('/_tenancy/{project}', fn (Project $project) => $project->name);

    $user = $this->teamA->owner->refresh();

    $this->actingAs($user)->get("/_tenancy/{$this->projectA->id}")->assertOk()->assertSee($this->projectA->name);
    $this->actingAs($user)->get("/_tenancy/{$this->projectB->id}")->assertNotFound();
});

it('follows the users current team and ignores teams they were removed from', function () {
    $user = $this->teamA->owner->refresh();
    $this->actingAs($user);

    expect(Project::pluck('id')->all())->toBe([$this->projectA->id]);

    // Pointing current_team_id at a team without membership must not grant access.
    $user->forceFill(['current_team_id' => $this->teamB->id])->save();
    $user->unsetRelation('currentTeam');
    app(\App\Support\Tenancy\TenantContext::class)->forget();

    expect(Project::count())->toBe(0);
});
