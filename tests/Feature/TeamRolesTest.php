<?php

use App\Enums\Role;
use App\Models\Team;
use App\Models\User;

/** @return array{0: Team, 1: array<string, User>} the first Owner role listed owns the team */
function teamWith(Role ...$roles): array
{
    $owner = User::factory()->create();
    $team = Team::factory()->for($owner, 'owner')->create(['plan' => 'business']);
    $users = ['owner' => $owner];
    foreach ($roles as $role) {
        if ($role === Role::Owner && isset($users['owner']) && $users['owner']->is($owner) && ! isset($users['_seen'])) {
            $users['_seen'] = true;

            continue;
        }
        $user = User::factory()->create(['current_team_id' => $team->id]);
        $team->addMember($user, $role);
        $users[$role->value] = $user;
    }
    unset($users['_seen']);

    return [$team, $users];
}

it('creates a personal team on registration', function () {
    $this->post('/register', [
        'name' => 'Ada', 'email' => 'ada@example.com',
        'password' => 'password-123', 'password_confirmation' => 'password-123',
    ])->assertRedirect(route('dashboard', absolute: false));

    $user = User::firstWhere('email', 'ada@example.com');
    expect($user->currentTeam->name)->toBe("Ada's Team")
        ->and($user->currentTeam->roleOf($user))->toBe(Role::Owner);
});

it('creates and switches teams', function () {
    $user = ownerWithTeam();
    $first = $user->currentTeam;

    $this->actingAs($user)->post('/teams', ['name' => 'Second'])->assertRedirect(route('teams.show'));
    expect($user->refresh()->currentTeam->name)->toBe('Second');

    $this->actingAs($user)->put('/current-team', ['team_id' => $first->id])->assertRedirect(route('dashboard'));
    expect($user->refresh()->current_team_id)->toBe($first->id);
});

it('cannot switch to a team the user does not belong to', function () {
    $user = ownerWithTeam();
    $other = Team::factory()->create();

    $this->actingAs($user)->put('/current-team', ['team_id' => $other->id])->assertForbidden();
    expect($user->refresh()->current_team_id)->not->toBe($other->id);
});

it('lets only owners and admins view settings actions', function (Role $role, bool $canInvite) {
    [$team, $users] = teamWith(Role::Owner, ...($role === Role::Owner ? [] : [$role]));
    $actor = $users[$role->value];

    $this->actingAs($actor)->get('/team')->assertOk()
        ->assertInertia(fn ($page) => $page->where('can.invite', $canInvite));
})->with([
    [Role::Owner, true],
    [Role::Admin, true],
    [Role::Member, false],
]);

it('enforces who can invite which role', function () {
    Mail::fake();
    [$team, $u] = teamWith(Role::Owner, Role::Admin, Role::Member);

    $this->actingAs($u['member'])->post('/team/invitations', ['email' => 'x@example.com', 'role' => 'member'])->assertForbidden();
    $this->actingAs($u['admin'])->post('/team/invitations', ['email' => 'x@example.com', 'role' => 'admin'])->assertForbidden();
    $this->actingAs($u['admin'])->post('/team/invitations', ['email' => 'x@example.com', 'role' => 'member'])->assertRedirect();
    $this->actingAs($u['owner'])->post('/team/invitations', ['email' => 'y@example.com', 'role' => 'admin'])->assertRedirect();
    $this->actingAs($u['owner'])->post('/team/invitations', ['email' => 'z@example.com', 'role' => 'owner'])->assertSessionHasErrors('role');
});

it('only owners change roles', function () {
    [$team, $u] = teamWith(Role::Owner, Role::Admin, Role::Member);

    $this->actingAs($u['admin'])->patch("/team/members/{$u['member']->id}", ['role' => 'admin'])->assertForbidden();
    $this->actingAs($u['owner'])->patch("/team/members/{$u['member']->id}", ['role' => 'admin'])->assertRedirect();

    expect($team->roleOf($u['member']))->toBe(Role::Admin);
});

it('applies the removal rules', function () {
    [$team, $u] = teamWith(Role::Owner, Role::Admin, Role::Member);
    $other = User::factory()->create(['current_team_id' => $team->id]);
    $team->addMember($other, Role::Member);

    // members cannot remove others; admins cannot remove admins/owner; nobody removes the owner
    $this->actingAs($u['member'])->delete("/team/members/{$other->id}")->assertForbidden();
    $this->actingAs($u['admin'])->delete("/team/members/{$u['owner']->id}")->assertForbidden();
    $this->actingAs($u['owner'])->delete("/team/members/{$u['owner']->id}")->assertForbidden();

    $this->actingAs($u['admin'])->delete("/team/members/{$other->id}")->assertRedirect();
    expect($team->hasMember($other))->toBeFalse();

    // members may leave
    $this->actingAs($u['member'])->delete("/team/members/{$u['member']->id}")->assertRedirect(route('dashboard'));
    expect($team->hasMember($u['member']))->toBeFalse();
});

it('transfers ownership', function () {
    [$team, $u] = teamWith(Role::Owner, Role::Member);

    $this->actingAs($u['member'])->post('/team/ownership', ['user_id' => $u['member']->id])->assertForbidden();
    $this->actingAs($u['owner'])->post('/team/ownership', ['user_id' => $u['owner']->id])->assertSessionHasErrors('user_id');
    $this->actingAs($u['owner'])->post('/team/ownership', ['user_id' => $u['member']->id])->assertRedirect();

    expect($team->refresh()->owner_id)->toBe($u['member']->id)
        ->and($team->roleOf($u['member']))->toBe(Role::Owner)
        ->and($team->roleOf($u['owner']))->toBe(Role::Admin);
});
