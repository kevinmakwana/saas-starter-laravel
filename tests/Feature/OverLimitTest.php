<?php

use App\Enums\Role;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/** A team that grew on a paid plan and was then dropped to Free: 5 projects (limit 3) and 3 members (limit 2). */
beforeEach(function () {
    $this->owner = ownerWithTeam('free');
    $this->team = $this->owner->currentTeam;
    $this->projects = asTenant($this->team, fn () => Project::factory()->count(5)->create());
    foreach ([Role::Admin, Role::Member] as $role) {
        $this->team->addMember(User::factory()->create(['current_team_id' => $this->team->id]), $role);
    }
});

it('keeps every existing project readable, editable and deletable', function () {
    $project = $this->projects->first();

    $this->actingAs($this->owner)->get('/projects')->assertOk();
    $this->actingAs($this->owner)->get("/projects/{$project->id}")->assertOk();
    $this->actingAs($this->owner)->put("/projects/{$project->id}", ['name' => 'Renamed'])->assertRedirect();
    $this->actingAs($this->owner)->delete("/projects/{$project->id}")->assertRedirect();

    expect(asTenant($this->team, fn () => Project::count()))->toBe(4);
});

it('blocks new projects and invitations until the team is back within the limits', function () {
    Mail::fake();

    $this->actingAs($this->owner)->post('/projects', ['name' => 'One more'])
        ->assertRedirect()->assertSessionHas('upgrade');
    $this->actingAs($this->owner)->post('/team/invitations', ['email' => 'new@example.com', 'role' => 'member'])
        ->assertSessionHas('upgrade');

    expect(asTenant($this->team, fn () => Project::count()))->toBe(5);
    Mail::assertNothingQueued();
});

it('allows creating again once usage drops below the limit', function () {
    asTenant($this->team, fn () => Project::query()->limit(3)->get()->each->delete());

    $this->actingAs($this->owner)->post('/projects', ['name' => 'Fits now'])->assertSessionMissing('upgrade');

    expect(asTenant($this->team, fn () => Project::count()))->toBe(3);
});

it('tells every member that the team is over its limits', function () {
    $member = $this->team->users()->wherePivot('role', 'member')->first();

    $this->actingAs($member)->get('/dashboard')->assertInertia(fn ($page) => $page
        ->where('auth.overLimit.plan', 'Free')
        ->where('auth.overLimit.items', [
            ['key' => 'projects', 'label' => 'projects', 'used' => 5, 'limit' => 3],
            ['key' => 'members', 'label' => 'members', 'used' => 3, 'limit' => 2],
        ]));
});

it('only reports the limits that are exceeded', function () {
    asTenant($this->team, fn () => Project::query()->limit(3)->get()->each->delete());

    $this->actingAs($this->owner)->get('/dashboard')->assertInertia(fn ($page) => $page
        ->where('auth.overLimit.items', [['key' => 'members', 'label' => 'members', 'used' => 3, 'limit' => 2]]));
});

it('shows no notice within the limits, on unlimited plans, or for pending invitations alone', function () {
    $this->team->forceFill(['plan' => 'business'])->save();
    $this->actingAs($this->owner)->get('/dashboard')->assertInertia(fn ($page) => $page->where('auth.overLimit', null));

    $fresh = ownerWithTeam('free');
    $fresh->currentTeam->invitations()->create(['email' => 'p@example.com', 'role' => Role::Member, 'expires_at' => now()->addDays(3)]);
    $this->actingAs($fresh)->get('/dashboard')->assertInertia(fn ($page) => $page->where('auth.overLimit', null));
});

it('does not seat an invited person while the team is full, and keeps the invitation for later', function () {
    $invitation = $this->team->invitations()->create(['email' => 'late@example.com', 'role' => Role::Member, 'expires_at' => now()->addDays(3)]);
    $invitee = User::factory()->create(['email' => 'late@example.com']);

    $this->actingAs($invitee)->get($invitation->acceptUrl())
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('error', fn ($message) => str_contains($message, 'full'));

    expect($this->team->hasMember($invitee))->toBeFalse()
        ->and($invitation->fresh()->accepted_at)->toBeNull();

    // After the owner upgrades, the same link works.
    $this->team->forceFill(['plan' => 'pro'])->save();
    $this->actingAs($invitee)->get($invitation->acceptUrl());

    expect($this->team->hasMember($invitee))->toBeTrue();
});
