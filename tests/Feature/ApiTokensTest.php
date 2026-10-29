<?php

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\PersonalAccessToken;
use App\Models\Project;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

beforeEach(function () {
    $this->owner = ownerWithTeam();
    $this->team = $this->owner->currentTeam;
});

/** A session in which the password was entered recently, as `password.confirm` requires. */
function recentlyConfirmed(): array
{
    return ['auth.password_confirmed_at' => time()];
}

/** The plain text of a new token for $user in $team. */
function tokenFor(User $user, Team $team, string $access = 'write', ?DateTimeInterface $expiresAt = null): string
{
    return $user->issueApiToken($team, 'CI', PersonalAccessToken::abilitiesFor($access), $expiresAt)->plainTextToken;
}

/** The API as the holder of $token. Guards are forgotten so a test can switch tokens between requests. */
function asToken(string $token): TestCase
{
    app('auth')->forgetGuards();
    app(TenantContext::class)->forget();

    return test()->withToken($token)->withHeader('Accept', 'application/json');
}

function teammate(Team $team, Role $role = Role::Member): User
{
    $user = User::factory()->create(['current_team_id' => $team->id]);
    $team->addMember($user, $role);

    return $user;
}

describe('making a token', function () {
    it('needs the password to be confirmed first', function () {
        $this->actingAs($this->owner)->post('/profile/api-tokens', ['name' => 'CI', 'team_id' => $this->team->id, 'access' => 'read'])
            ->assertRedirect(route('password.confirm'));

        expect(PersonalAccessToken::count())->toBe(0);
    });

    it('is tied to one team and shown once', function () {
        $response = $this->actingAs($this->owner)->withSession(recentlyConfirmed())
            ->post('/profile/api-tokens', ['name' => 'Deploy bot', 'team_id' => $this->team->id, 'access' => 'write', 'expires' => '30'])
            ->assertRedirect(route('profile.edit'));

        $token = PersonalAccessToken::sole();
        expect($token->team_id)->toBe($this->team->id)
            ->and($token->tokenable_id)->toBe($this->owner->id)
            ->and($token->name)->toBe('Deploy bot')
            ->and($token->abilities)->toBe(['projects:read', 'projects:write'])
            ->and($token->expires_at->isSameDay(now()->addDays(30)))->toBeTrue();

        $plain = $response->getSession()->get('newToken');
        expect($plain)->toStartWith($token->id.'|');

        // The next page shows it once; after that the list has no secret.
        $this->actingAs($this->owner)->get('/profile')->assertInertia(fn ($page) => $page->where('newToken', $plain));
        $this->actingAs($this->owner)->get('/profile')->assertInertia(fn ($page) => $page
            ->where('newToken', null)
            ->has('apiTokens', 1, fn ($t) => $t->where('name', 'Deploy bot')->where('team', $this->team->name)->where('access', 'write')->hasAll(['id', 'last_used_at', 'expires_at', 'created_at'])->etc()));
    });

    it('can be read-only and can be set never to expire', function () {
        $this->actingAs($this->owner)->withSession(recentlyConfirmed())
            ->post('/profile/api-tokens', ['name' => 'Report', 'team_id' => $this->team->id, 'access' => 'read', 'expires' => 'never']);

        $token = PersonalAccessToken::sole();
        expect($token->abilities)->toBe(['projects:read'])->and($token->expires_at)->toBeNull();
    });

    it('expires in 90 days unless told otherwise', function () {
        $this->actingAs($this->owner)->withSession(recentlyConfirmed())
            ->post('/profile/api-tokens', ['name' => 'Default', 'team_id' => $this->team->id, 'access' => 'read']);

        expect(PersonalAccessToken::sole()->expires_at->isSameDay(now()->addDays(90)))->toBeTrue();
    });

    it('is refused for a team the user is not in', function () {
        $other = Team::factory()->create();

        $this->actingAs($this->owner)->withSession(recentlyConfirmed())
            ->post('/profile/api-tokens', ['name' => 'Sneaky', 'team_id' => $other->id, 'access' => 'read'])
            ->assertSessionHasErrors('team_id');

        expect(PersonalAccessToken::count())->toBe(0);
    });

    it('validates the name, the access level and the expiry', function (array $data, string $field) {
        $this->actingAs($this->owner)->withSession(recentlyConfirmed())
            ->post('/profile/api-tokens', $data + ['name' => 'CI', 'team_id' => $this->team->id, 'access' => 'read'])
            ->assertSessionHasErrors($field);
    })->with([
        'no name' => [['name' => ''], 'name'],
        'long name' => [['name' => str_repeat('a', 101)], 'name'],
        'unknown access' => [['access' => 'admin'], 'access'],
        'unknown expiry' => [['expires' => '5'], 'expires'],
    ]);

    it('is written to the audit log of its team, without the secret', function () {
        $this->actingAs($this->owner)->withSession(recentlyConfirmed())
            ->post('/profile/api-tokens', ['name' => 'Deploy bot', 'team_id' => $this->team->id, 'access' => 'write']);

        $entry = AuditLog::withoutGlobalScopes()->where('action', 'apitoken.created')->sole();
        expect($entry->tenant_id)->toBe($this->team->id)
            ->and($entry->user_id)->toBe($this->owner->id)
            ->and($entry->summary)->toContain('Deploy bot');
    });

    it('is for signed-in users only', function () {
        $this->post('/profile/api-tokens', ['name' => 'x'])->assertRedirect('/login');
        $this->delete('/profile/api-tokens/1')->assertRedirect('/login');
    });
});

describe('revoking a token', function () {
    it('stops it working at once', function () {
        $plain = tokenFor($this->owner, $this->team);
        asToken($plain)->getJson('/api/v1/projects')->assertOk();

        $this->actingAs($this->owner)->delete('/profile/api-tokens/'.PersonalAccessToken::sole()->id)->assertRedirect(route('profile.edit'));

        expect(PersonalAccessToken::count())->toBe(0);
        asToken($plain)->getJson('/api/v1/projects')->assertUnauthorized();
        expect(AuditLog::withoutGlobalScopes()->where('action', 'apitoken.revoked')->count())->toBe(1);
    });

    it('is not possible for someone else\'s token', function () {
        $other = teammate($this->team);
        tokenFor($other, $this->team);
        $token = PersonalAccessToken::sole();

        $this->actingAs($this->owner)->delete('/profile/api-tokens/'.$token->id)->assertNotFound();

        expect(PersonalAccessToken::count())->toBe(1);
    });
});

describe('using a token', function () {
    it('answers with JSON, not a redirect, when there is no token or a wrong one', function () {
        $this->getJson('/api/v1/projects')->assertUnauthorized()->assertJson(['message' => 'Unauthenticated.']);
        // Even a client that does not ask for JSON gets JSON.
        $this->get('/api/v1/projects')->assertUnauthorized()->assertHeader('Content-Type', 'application/json');
        asToken('1|not-a-real-token')->getJson('/api/v1/projects')->assertUnauthorized();
    });

    it('does not accept the browser session', function () {
        $this->actingAs($this->owner)->getJson('/api/v1/projects')->assertUnauthorized();
    });

    it('stops working when it expires', function () {
        $plain = tokenFor($this->owner, $this->team, 'read', now()->addDay());
        asToken($plain)->getJson('/api/v1/projects')->assertOk();

        $this->travel(2)->days();

        asToken($plain)->getJson('/api/v1/projects')->assertUnauthorized();
    });

    it('records when it was last used', function () {
        $plain = tokenFor($this->owner, $this->team, 'read');
        expect(PersonalAccessToken::sole()->last_used_at)->toBeNull();

        asToken($plain)->getJson('/api/v1/projects');

        expect(PersonalAccessToken::sole()->last_used_at)->not->toBeNull();
    });

    it('only ever sees its own team, even when the user works in another', function () {
        $second = Team::factory()->create(['plan' => 'business']);
        $second->addMember($this->owner, Role::Owner);
        $this->owner->switchTeam($second);
        $mine = asTenant($this->team, fn () => Project::factory()->create(['name' => 'In the token team']));
        asTenant($second, fn () => Project::factory()->create(['name' => 'In the current team']));
        $plain = tokenFor($this->owner, $this->team, 'read');

        asToken($plain)->getJson('/api/v1/projects')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'In the token team');
        asToken($plain)->getJson('/api/v1/projects/'.asTenant($second, fn () => Project::first()->id))->assertNotFound();
        asToken($plain)->getJson('/api/v1/projects/'.$mine->id)->assertOk();
    });

    it('cannot reach the projects of a team it was not made for', function () {
        $other = Team::factory()->create();
        $foreign = asTenant($other, fn () => Project::factory()->create());
        $plain = tokenFor($this->owner, $this->team);

        asToken($plain)->getJson('/api/v1/projects/'.$foreign->id)->assertNotFound();
        asToken($plain)->patchJson('/api/v1/projects/'.$foreign->id, ['name' => 'Mine now'])->assertNotFound();
        asToken($plain)->deleteJson('/api/v1/projects/'.$foreign->id)->assertNotFound();
        expect(Project::withoutGlobalScopes()->find($foreign->id)->name)->not->toBe('Mine now');
    });

    it('stops working when the user leaves the team', function () {
        $member = teammate($this->team);
        $plain = tokenFor($member, $this->team);
        asToken($plain)->getJson('/api/v1/projects')->assertOk();

        $this->team->removeMember($member);

        expect(PersonalAccessToken::count())->toBe(0);
        asToken($plain)->getJson('/api/v1/projects')->assertUnauthorized();
    });

    it('is refused if the membership is gone even though the token row is left', function () {
        $member = teammate($this->team);
        $plain = tokenFor($member, $this->team);
        $this->team->users()->detach($member->id);

        asToken($plain)->getJson('/api/v1/projects')->assertForbidden();
    });

    it('goes when the team is deleted, and with the account', function () {
        $gone = Team::factory()->create();
        tokenFor($gone->owner, $gone);
        $solo = ownerWithTeam();
        tokenFor($solo, $solo->currentTeam);
        tokenFor($this->owner, $this->team);

        $gone->delete();
        $solo->delete();

        expect(PersonalAccessToken::pluck('tokenable_id')->all())->toBe([$this->owner->id]);
    });

    it('has expired ones pruned on a schedule', function () {
        $scheduled = collect(app(Schedule::class)->events())->map->command;

        expect($scheduled->filter(fn ($c) => str_contains((string) $c, 'sanctum:prune-expired')))->not->toBeEmpty();
    });
});

describe('what a token may do', function () {
    it('lets a read token look but not change', function () {
        $project = asTenant($this->team, fn () => Project::factory()->create());
        $plain = tokenFor($this->owner, $this->team, 'read');

        asToken($plain)->getJson('/api/v1/projects')->assertOk();
        asToken($plain)->getJson('/api/v1/projects/'.$project->id.'/tasks')->assertOk();
        asToken($plain)->postJson('/api/v1/projects', ['name' => 'New'])->assertForbidden();
        asToken($plain)->patchJson('/api/v1/projects/'.$project->id, ['name' => 'New'])->assertForbidden();
        asToken($plain)->deleteJson('/api/v1/projects/'.$project->id)->assertForbidden();
        asToken($plain)->postJson('/api/v1/projects/'.$project->id.'/tasks', ['title' => 'x'])->assertForbidden();
    });

    it('follows the role of its owner in the team', function () {
        $project = asTenant($this->team, fn () => Project::factory()->create());
        $member = teammate($this->team);
        $plain = tokenFor($member, $this->team);

        // A plain member may not delete projects, with or without a token.
        asToken($plain)->deleteJson('/api/v1/projects/'.$project->id)->assertForbidden();

        $this->team->addMember($member, Role::Admin);
        asToken($plain)->deleteJson('/api/v1/projects/'.$project->id)->assertNoContent();
    });
});

describe('projects', function () {
    it('lists, shows, creates, updates and deletes', function () {
        $plain = tokenFor($this->owner, $this->team);

        $created = asToken($plain)->postJson('/api/v1/projects', ['name' => 'Launch', 'description' => 'Ship it'])
            ->assertCreated()->assertJsonPath('data.name', 'Launch')->assertJsonPath('data.description', 'Ship it')
            ->assertJsonStructure(['data' => ['id', 'name', 'description', 'tasks_count', 'created_at', 'updated_at']]);
        $id = $created->json('data.id');

        asToken($plain)->getJson('/api/v1/projects')->assertOk()->assertJsonCount(1, 'data')->assertJsonStructure(['data', 'links', 'meta']);
        asToken($plain)->getJson("/api/v1/projects/{$id}")->assertOk()->assertJsonPath('data.name', 'Launch');
        asToken($plain)->patchJson("/api/v1/projects/{$id}", ['name' => 'Launch 2'])->assertOk()->assertJsonPath('data.name', 'Launch 2');
        asToken($plain)->deleteJson("/api/v1/projects/{$id}")->assertNoContent();
        asToken($plain)->getJson("/api/v1/projects/{$id}")->assertNotFound();
    });

    it('explains validation problems in JSON', function () {
        asToken(tokenFor($this->owner, $this->team))->postJson('/api/v1/projects', ['name' => ''])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
    });

    it('stops at the plan limit with a 402 and an upgrade message', function () {
        $free = ownerWithTeam('free');
        asTenant($free->currentTeam, fn () => Project::factory()->count(3)->create());

        asToken(tokenFor($free, $free->currentTeam))->postJson('/api/v1/projects', ['name' => 'One too many'])
            ->assertStatus(402)->assertJsonStructure(['message']);

        expect(asTenant($free->currentTeam, fn () => Project::count()))->toBe(3);
    });

    it('pages its list', function () {
        asTenant($this->team, fn () => Project::factory()->count(30)->create());

        asToken(tokenFor($this->owner, $this->team, 'read'))->getJson('/api/v1/projects')
            ->assertOk()->assertJsonCount(25, 'data')->assertJsonPath('meta.total', 30);
    });
});

describe('tasks', function () {
    beforeEach(function () {
        $this->project = asTenant($this->team, fn () => Project::factory()->create());
        $this->plain = tokenFor($this->owner, $this->team);
    });

    it('lists, creates, updates and deletes', function () {
        $created = asToken($this->plain)->postJson("/api/v1/projects/{$this->project->id}/tasks", ['title' => 'Write docs'])
            ->assertCreated()->assertJsonPath('data.title', 'Write docs')->assertJsonPath('data.status', 'todo')
            ->assertJsonStructure(['data' => ['id', 'project_id', 'title', 'status', 'created_at', 'updated_at']]);
        $id = $created->json('data.id');

        asToken($this->plain)->getJson("/api/v1/projects/{$this->project->id}/tasks")->assertOk()->assertJsonCount(1, 'data');
        asToken($this->plain)->patchJson("/api/v1/projects/{$this->project->id}/tasks/{$id}", ['title' => 'Write docs', 'status' => 'done'])
            ->assertOk()->assertJsonPath('data.status', 'done');
        asToken($this->plain)->deleteJson("/api/v1/projects/{$this->project->id}/tasks/{$id}")->assertNoContent();
        expect(asTenant($this->team, fn () => Task::count()))->toBe(0);
    });

    it('filters by status', function () {
        asTenant($this->team, function () {
            $this->project->tasks()->create(['title' => 'a', 'status' => 'todo']);
            $this->project->tasks()->create(['title' => 'b', 'status' => 'done']);
        });

        asToken($this->plain)->getJson("/api/v1/projects/{$this->project->id}/tasks?status=done")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'b');
    });

    it('validates in JSON', function () {
        asToken($this->plain)->postJson("/api/v1/projects/{$this->project->id}/tasks", ['title' => 'x', 'status' => 'nope'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
    });

    it('does not reach a task through the wrong project', function () {
        $other = asTenant($this->team, fn () => Project::factory()->create());
        $task = asTenant($this->team, fn () => $other->tasks()->create(['title' => 'Elsewhere', 'status' => 'todo']));

        asToken($this->plain)->patchJson("/api/v1/projects/{$this->project->id}/tasks/{$task->id}", ['title' => 'Moved'])->assertNotFound();
        asToken($this->plain)->deleteJson("/api/v1/projects/{$this->project->id}/tasks/{$task->id}")->assertNotFound();
    });
});

describe('the paper trail', function () {
    it('records what a token did, as its owner and marked as the API', function () {
        $plain = tokenFor($this->owner, $this->team);

        $id = asToken($plain)->postJson('/api/v1/projects', ['name' => 'Via API'])->json('data.id');
        asToken($plain)->postJson("/api/v1/projects/{$id}/tasks", ['title' => 'A task']);
        asToken($plain)->deleteJson("/api/v1/projects/{$id}");

        $entries = AuditLog::withoutGlobalScopes()->where('tenant_id', $this->team->id)->whereIn('action', ['project.created', 'task.created', 'project.deleted'])->get();
        expect($entries->pluck('action')->sort()->values()->all())->toBe(['project.created', 'project.deleted', 'task.created'])
            ->and($entries->pluck('user_id')->unique()->all())->toBe([$this->owner->id])
            ->and($entries->every(fn ($e) => str_ends_with($e->summary, '(via API)')))->toBeTrue();
    });
});

describe('rate limiting', function () {
    it('allows a limited number of requests a minute per token, then says when to retry', function () {
        config(['throttle.api_per_minute' => 3]);
        RateLimiter::clear('api');
        $plain = tokenFor($this->owner, $this->team, 'read');
        $other = tokenFor($this->owner, $this->team, 'read');

        foreach (range(1, 3) as $_) {
            asToken($plain)->getJson('/api/v1/projects')->assertOk();
        }

        asToken($plain)->getJson('/api/v1/projects')->assertStatus(429)->assertHeader('Retry-After');
        // Each token has its own allowance.
        asToken($other)->getJson('/api/v1/projects')->assertOk();
    });
});

describe('personal data', function () {
    it('lists the tokens in the data download, never the secret', function () {
        $plain = tokenFor($this->owner, $this->team, 'read', now()->addDays(10));

        $json = $this->actingAs($this->owner)->from('/profile')->post('/profile/export', ['password' => 'password'])->streamedContent();
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        expect($data['api_tokens'])->toHaveCount(1)
            ->and(array_keys($data['api_tokens'][0]))->toBe(['name', 'team_id', 'access', 'last_used_at', 'expires_at', 'created_at'])
            ->and($data['api_tokens'][0]['access'])->toBe('read')
            ->and($json)->not->toContain(explode('|', $plain)[1]);
    });
});
