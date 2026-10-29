<?php

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Project;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->user = ownerWithTeam();
    $this->team = $this->user->currentTeam;
});

/** @param array<string, mixed> $input */
function requestExport(?User $as = null, array $input = ['password' => 'password']): TestResponse
{
    return test()->actingAs($as ?? test()->user)->from('/profile')->post('/profile/export', $input);
}

/** @return array<string, mixed> */
function exportedData(?User $as = null): array
{
    $response = requestExport($as)->assertOk();

    return json_decode($response->streamedContent(), true, flags: JSON_THROW_ON_ERROR);
}

function joinTeam(User $user, Team $team, Role $role = Role::Member): void
{
    $team->addMember($user, $role);
}

describe('the download', function () {
    it('is a JSON file that is not cached', function () {
        $response = requestExport()->assertOk();

        expect($response->headers->get('Content-Disposition'))->toContain('attachment')->toContain('account-data.json')
            ->and($response->headers->get('Content-Type'))->toContain('application/json')
            ->and($response->headers->get('Cache-Control'))->toContain('no-store');
    });

    it('has a stable shape', function () {
        $data = exportedData();

        expect(array_keys($data))->toBe(['exported_at', 'account', 'teams', 'invitations', 'notifications', 'audit_log'])
            ->and(array_keys($data['account']))->toBe(['id', 'name', 'email', 'email_verified_at', 'created_at', 'pending_email'])
            ->and(array_keys($data['invitations']))->toBe(['sent', 'received']);
    });

    it('describes the account', function () {
        $this->user->forceFill(['pending_email' => 'next@example.test', 'pending_email_expires_at' => now()->addHour()])->save();

        $account = exportedData()['account'];

        expect($account['id'])->toBe($this->user->id)
            ->and($account['name'])->toBe($this->user->name)
            ->and($account['email'])->toBe($this->user->email)
            ->and($account['email_verified_at'])->not->toBeNull()
            ->and($account['pending_email'])->toBe('next@example.test');
    });

    it('leaves out an email change that has run out', function () {
        $this->user->forceFill(['pending_email' => 'old@example.test', 'pending_email_expires_at' => now()->subMinute()])->save();

        expect(exportedData()['account']['pending_email'])->toBeNull();
    });

    it('lists every team with the role and whether they own it', function () {
        $other = Team::factory()->create(['name' => 'Elsewhere']);
        joinTeam($this->user, $other, Role::Admin);
        Team::factory()->create(['name' => 'Not mine']);

        $teams = collect(exportedData()['teams'])->keyBy('name');

        expect($teams->keys()->all())->toContain($this->team->name, 'Elsewhere')->not->toContain('Not mine')
            ->and($teams[$this->team->name])->toMatchArray(['id' => $this->team->id, 'role' => 'owner', 'is_owner' => true])
            ->and($teams['Elsewhere'])->toMatchArray(['role' => 'admin', 'is_owner' => false])
            ->and($teams['Elsewhere']['joined_at'])->not->toBeNull();
    });

    it('works for a user with no team at all', function () {
        $loner = User::factory()->create();

        $data = exportedData($loner);

        expect($data['teams'])->toBe([])->and($data['audit_log'])->toBe([])->and($data['account']['email'])->toBe($loner->email);
    });
});

describe('audit entries', function () {
    it('contains what the user did in every team, and nothing else', function () {
        $other = Team::factory()->create();
        joinTeam($this->user, $other);
        $stranger = User::factory()->create();
        asTenant($this->team, function () use ($stranger) {
            AuditLog::create(['user_id' => $this->user->id, 'action' => 'project.created', 'summary' => 'Created Alpha']);
            AuditLog::create(['user_id' => $stranger->id, 'action' => 'project.created', 'summary' => 'Created Theirs']);
            AuditLog::create(['user_id' => null, 'action' => 'billing.changed', 'summary' => 'System entry']);
        });
        asTenant($other, fn () => AuditLog::create(['user_id' => $this->user->id, 'action' => 'task.created', 'summary' => 'Created Beta']));

        $entries = collect(exportedData()['audit_log'])->where('action', '!=', 'account.exported');

        expect($entries->pluck('summary')->sort()->values()->all())->toBe(['Created Alpha', 'Created Beta'])
            ->and($entries->firstWhere('summary', 'Created Beta'))->toMatchArray(['team_id' => $other->id, 'action' => 'task.created'])
            ->and(array_keys($entries->first()))->toBe(['id', 'team_id', 'action', 'subject_type', 'subject_id', 'summary', 'created_at']);
    });

    it('is complete for a long history', function () {
        asTenant($this->team, function () {
            foreach (range(1, 60) as $i) {
                AuditLog::create(['user_id' => $this->user->id, 'action' => 'task.updated', 'summary' => "Entry {$i}"]);
            }
        });

        $summaries = collect(exportedData()['audit_log'])->pluck('summary');

        expect($summaries->filter(fn ($s) => str_starts_with($s, 'Entry '))->count())->toBe(60);
    });

    it('is a plain JSON list even when it is empty', function () {
        $loner = User::factory()->create();

        $json = requestExport($loner)->streamedContent();

        expect($json)->toContain('"audit_log": []');
    });
});

describe('invitations', function () {
    it('lists the ones the user sent and the live ones addressed to them', function () {
        $sent = $this->team->invitations()->create(['email' => 'friend@example.test', 'role' => Role::Member, 'invited_by' => $this->user->id, 'expires_at' => now()->addDay()]);
        $inviter = ownerWithTeam();
        $inviter->currentTeam->invitations()->create(['email' => $this->user->email, 'role' => Role::Admin, 'invited_by' => $inviter->id, 'expires_at' => now()->addDay()]);
        $expired = ownerWithTeam();
        $expired->currentTeam->invitations()->create(['email' => $this->user->email, 'role' => Role::Member, 'invited_by' => $expired->id, 'expires_at' => now()->subDay()]);
        $unrelated = ownerWithTeam();
        $unrelated->currentTeam->invitations()->create(['email' => 'someone@else.test', 'role' => Role::Member, 'invited_by' => $unrelated->id, 'expires_at' => now()->addDay()]);

        $invitations = exportedData()['invitations'];

        expect($invitations['sent'])->toHaveCount(1)
            ->and($invitations['sent'][0])->toMatchArray(['team_id' => $this->team->id, 'email' => 'friend@example.test', 'role' => 'member', 'status' => 'pending'])
            ->and($invitations['received'])->toHaveCount(1)
            ->and($invitations['received'][0])->toMatchArray(['team_id' => $inviter->currentTeam->id, 'team_name' => $inviter->currentTeam->name, 'role' => 'admin']);
        expect(TeamInvitation::count())->toBe(4);
    });

    it('marks sent invitations as pending, accepted or expired', function () {
        $make = fn (string $email, array $state) => $this->team->invitations()->create(['email' => $email, 'role' => Role::Member, 'invited_by' => $this->user->id, 'expires_at' => now()->addDay()])
            ->forceFill($state)->save();
        $make('a@example.test', []);
        $make('b@example.test', ['accepted_at' => now()]);
        $make('c@example.test', ['expires_at' => now()->subDay()]);

        $status = collect(exportedData()['invitations']['sent'])->pluck('status', 'email');

        expect($status->all())->toBe(['a@example.test' => 'pending', 'b@example.test' => 'accepted', 'c@example.test' => 'expired']);
    });
});

describe('what it never contains', function () {
    it('has no password hash, token or other people', function () {
        $this->user->forceFill(['remember_token' => 'remember-me-secret'])->save();
        $colleague = User::factory()->create(['name' => 'Colleague Person', 'email' => 'colleague@example.test']);
        joinTeam($colleague, $this->team);
        asTenant($this->team, fn () => Project::factory()->create(['name' => 'Team Secret Project']));

        $json = requestExport()->streamedContent();

        expect($json)->not->toContain($this->user->fresh()->password)
            ->not->toContain('remember-me-secret')
            ->not->toContain('Colleague Person')
            ->not->toContain('colleague@example.test')
            ->not->toContain('Team Secret Project')
            ->not->toContain('remember_token')
            ->not->toContain('"password"');
    });

    it('is only ever about the signed-in user', function () {
        $stranger = ownerWithTeam();

        $data = exportedData();

        expect($data['account']['id'])->toBe($this->user->id)
            ->and(collect($data['teams'])->pluck('id')->all())->not->toContain($stranger->currentTeam->id);
    });
});

describe('safeguards', function () {
    it('needs the current password', function () {
        requestExport(input: ['password' => 'wrong'])->assertSessionHasErrors('password')->assertRedirect('/profile');
        requestExport(input: [])->assertSessionHasErrors('password');

        expect(asTenant($this->team, fn () => AuditLog::where('action', 'account.exported')->count()))->toBe(0);
    });

    it('is for signed-in users only', function () {
        $this->post('/profile/export', ['password' => 'password'])->assertRedirect('/login');
    });

    it('cannot be triggered with a plain link', function () {
        $this->actingAs($this->user)->get('/profile/export')->assertStatus(405);
    });

    it('is limited to three downloads an hour per user', function () {
        foreach (range(1, 3) as $i) {
            requestExport()->assertOk();
        }

        requestExport()->assertStatus(429);
        requestExport(ownerWithTeam())->assertOk();
    });

    it('also counts attempts with a wrong password', function () {
        foreach (range(1, 3) as $i) {
            requestExport(input: ['password' => 'wrong'])->assertSessionHasErrors('password');
        }

        requestExport()->assertStatus(429);
    });

    it('is recorded in the audit log without any of the data', function () {
        requestExport()->assertOk();

        $entry = asTenant($this->team, fn () => AuditLog::where('action', 'account.exported')->first());

        expect($entry->summary)->toBe('Downloaded their account data')
            ->and($entry->user_id)->toBe($this->user->id)
            ->and($entry->subject_type)->toBeNull();
    });

    it('leaves no audit entry for a user without a team', function () {
        requestExport(User::factory()->create())->assertOk();

        expect(AuditLog::withoutGlobalScopes()->where('action', 'account.exported')->count())->toBe(0);
    });
});
