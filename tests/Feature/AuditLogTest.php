<?php

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->owner = ownerWithTeam('business');
    $this->team = $this->owner->currentTeam;
});

it('records who did what', function () {
    $this->actingAs($this->owner)->post('/projects', ['name' => 'Roadmap'])->assertRedirect();

    $entry = asTenant($this->team, fn () => AuditLog::with('user')->latest('id')->first());
    expect($entry->action)->toBe('project.created')
        ->and($entry->summary)->toContain('Roadmap')
        ->and($entry->user_id)->toBe($this->owner->id)
        ->and($entry->tenant_id)->toBe($this->team->id);
});

it('records team and billing changes', function () {
    Mail::fake();
    $this->actingAs($this->owner)->post('/team/invitations', ['email' => 'x@example.com', 'role' => 'member']);
    $this->actingAs($this->owner)->post('/billing/change', ['plan' => 'pro']);

    $actions = asTenant($this->team, fn () => AuditLog::pluck('action')->all());
    expect($actions)->toContain('member.invited', 'billing.changed');
});

it('is visible to owners and admins on the Business plan', function () {
    $admin = User::factory()->create(['current_team_id' => $this->team->id]);
    $this->team->addMember($admin, Role::Admin);
    asTenant($this->team, fn () => AuditLog::create(['action' => 'x', 'summary' => 'Something happened']));

    foreach ([$this->owner, $admin] as $user) {
        $this->actingAs($user)->get('/audit-log')->assertOk()
            ->assertInertia(fn ($page) => $page->component('AuditLog/Index')->has('entries.data', 1));
    }
});

it('is hidden from members', function () {
    $member = User::factory()->create(['current_team_id' => $this->team->id]);
    $this->team->addMember($member, Role::Member);

    $this->actingAs($member)->get('/audit-log')->assertForbidden();
});

it('is gated off below the Business plan', function () {
    $this->team->forceFill(['plan' => 'pro'])->save();

    $this->actingAs($this->owner)->get('/audit-log')->assertRedirect(route('billing.show'));
});

it('only shows the current teams entries', function () {
    $other = Team::factory()->create(['plan' => 'business']);
    asTenant($other, fn () => AuditLog::create(['action' => 'secret', 'summary' => 'Other team activity']));
    asTenant($this->team, fn () => AuditLog::create(['action' => 'mine', 'summary' => 'Our activity']));

    $this->actingAs($this->owner)->get('/audit-log')->assertInertia(fn ($page) => $page
        ->has('entries.data', 1)->where('entries.data.0.action', 'mine'));
});

/** Entries of a team, oldest first, regardless of who is signed in. */
function entriesOf(Team $team): array
{
    return asTenant($team, fn () => AuditLog::orderBy('id')->get()->all());
}

describe('user-level events', function () {
    it('records a login against the users current team', function () {
        $this->post('/login', ['email' => $this->owner->email, 'password' => 'password'])->assertRedirect();

        $entry = collect(entriesOf($this->team))->firstWhere('action', 'auth.login');
        expect($entry)->not->toBeNull()
            ->and($entry->user_id)->toBe($this->owner->id)
            ->and($entry->summary)->toBe('Signed in');
    });

    it('does not record a failed login', function () {
        $this->post('/login', ['email' => $this->owner->email, 'password' => 'wrong-password']);

        expect(entriesOf($this->team))->toBeEmpty();
    });

    it('records nothing for a user who has no current team', function () {
        $loner = User::factory()->create();

        $this->post('/login', ['email' => $loner->email, 'password' => 'password'])->assertRedirect();

        expect(AuditLog::withoutGlobalScopes()->count())->toBe(0);
    });

    it('records which profile fields changed, and the new email but never the old one', function () {
        $oldEmail = $this->owner->email;

        $this->actingAs($this->owner)->patch('/profile', ['name' => 'Renamed Owner', 'email' => 'new-address@example.com'])->assertRedirect();

        $entry = collect(entriesOf($this->team))->firstWhere('action', 'profile.updated');
        expect($entry->summary)->toContain('name', 'email', 'new-address@example.com')
            ->and($entry->summary)->not->toContain($oldEmail);
    });

    it('records nothing when a profile save changes nothing', function () {
        $this->actingAs($this->owner)->patch('/profile', ['name' => $this->owner->name, 'email' => $this->owner->email])->assertRedirect();

        expect(collect(entriesOf($this->team))->where('action', 'profile.updated'))->toBeEmpty();
    });

    it('records a password change made in the app, without any password material', function () {
        $this->actingAs($this->owner)->from('/profile')->put('/password', [
            'current_password' => 'password',
            'password' => 'a-new-Passw0rd!',
            'password_confirmation' => 'a-new-Passw0rd!',
        ])->assertSessionHasNoErrors();

        $entry = collect(entriesOf($this->team))->firstWhere('action', 'password.changed');
        expect($entry)->not->toBeNull()->and($entry->user_id)->toBe($this->owner->id)
            ->and($entry->summary)->not->toContain('Passw0rd');
    });

    it('records a password reset, even though nobody is signed in', function () {
        Notification::fake();
        $this->post('/forgot-password', ['email' => $this->owner->email]);

        Notification::assertSentTo($this->owner, ResetPassword::class, function ($notification) {
            $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $this->owner->email,
                'password' => 'a-new-Passw0rd!',
                'password_confirmation' => 'a-new-Passw0rd!',
            ])->assertSessionHasNoErrors();

            return true;
        });

        $entry = collect(entriesOf($this->team))->firstWhere('action', 'password.changed');
        expect($entry)->not->toBeNull()->and($entry->summary)->toBe('Reset their password');
    });

    it('records a new team in that team, with the creator as actor', function () {
        $this->actingAs($this->owner)->post('/teams', ['name' => 'Skunkworks'])->assertRedirect();

        $created = Team::where('name', 'Skunkworks')->firstOrFail();
        $entry = collect(entriesOf($created))->firstWhere('action', 'team.created');
        expect($entry->user_id)->toBe($this->owner->id)
            ->and($entry->summary)->toContain('Skunkworks')
            ->and($entry->subject_type)->toBe('Team')
            ->and($entry->subject_id)->toBe($created->id);
    });

    it('records a rename with the old and the new name', function () {
        $old = $this->team->name;

        $this->actingAs($this->owner)->patch('/team', ['name' => 'Brand New Name'])->assertRedirect();

        $entry = collect(entriesOf($this->team))->firstWhere('action', 'team.renamed');
        expect($entry->summary)->toContain($old, 'Brand New Name');
    });

    it('does not record a rename that keeps the same name', function () {
        $this->actingAs($this->owner)->patch('/team', ['name' => $this->team->name])->assertRedirect();

        expect(collect(entriesOf($this->team))->where('action', 'team.renamed'))->toBeEmpty();
    });
});

describe('append-only', function () {
    beforeEach(function () {
        $this->entry = asTenant($this->team, fn () => AuditLog::create(['action' => 'x', 'summary' => 'Original']));
    });

    it('refuses to update an entry', function () {
        expect(fn () => asTenant($this->team, fn () => $this->entry->update(['summary' => 'Tampered'])))->toThrow(LogicException::class);
        expect(DB::table('audit_logs')->where('id', $this->entry->id)->value('summary'))->toBe('Original');
    });

    it('refuses to delete an entry', function () {
        expect(fn () => asTenant($this->team, fn () => $this->entry->delete()))->toThrow(LogicException::class);
        expect(fn () => asTenant($this->team, fn () => AuditLog::destroy($this->entry->id)))->toThrow(LogicException::class);
        expect(DB::table('audit_logs')->count())->toBe(1);
    });

    it('still goes away with its team, by cascade', function () {
        $this->team->delete();

        expect(DB::table('audit_logs')->count())->toBe(0);
    });

    it('is still removed by retention pruning', function () {
        config(['retention.audit_logs_days' => 30]);
        DB::table('audit_logs')->update(['created_at' => now()->subDays(60)]);

        $this->artisan('model:prune', ['--model' => AuditLog::class])->assertSuccessful();

        expect(DB::table('audit_logs')->count())->toBe(0);
    });
});

describe('export', function () {
    /** @return list<list<string|null>> */
    function exportedRows($response): array
    {
        return array_map('str_getcsv', array_values(array_filter(explode("\n", $response->streamedContent()))));
    }

    it('downloads the teams entries as a CSV', function () {
        asTenant($this->team, fn () => AuditLog::create(['user_id' => $this->owner->id, 'action' => 'project.created', 'subject_type' => 'Project', 'subject_id' => 7, 'summary' => 'Created project Roadmap']));
        asTenant($this->team, fn () => AuditLog::create(['action' => 'billing.synced', 'summary' => 'Synced from Stripe']));
        $other = Team::factory()->create(['plan' => 'business']);
        asTenant($other, fn () => AuditLog::create(['action' => 'secret', 'summary' => 'Other team activity']));

        $response = $this->actingAs($this->owner)->get('/audit-log/export')->assertOk();

        expect($response->headers->get('Content-Type'))->toStartWith('text/csv')
            ->and($response->headers->get('Content-Disposition'))->toContain('attachment')->toContain('audit-log.csv');

        $rows = exportedRows($response);
        expect($rows[0])->toBe(['id', 'at', 'actor', 'action', 'subject_type', 'subject_id', 'summary'])
            ->and($rows)->toHaveCount(3)
            ->and($rows[1][2])->toBe($this->owner->name)
            ->and($rows[1][3])->toBe('project.created')
            ->and($rows[1][4])->toBe('Project')
            ->and($rows[1][5])->toBe('7')
            ->and($rows[2][2])->toBe('System')
            ->and(collect($rows)->flatten()->contains('Other team activity'))->toBeFalse();
    });

    it('neutralizes cells a spreadsheet would run as a formula', function () {
        asTenant($this->team, fn () => AuditLog::create(['action' => 'project.created', 'summary' => '=HYPERLINK("http://evil.test","click")']));

        $rows = exportedRows($this->actingAs($this->owner)->get('/audit-log/export')->assertOk());

        expect($rows[1][6])->toStartWith("'=");
    });

    it('is open to admins, closed to members and guests', function () {
        $admin = User::factory()->create(['current_team_id' => $this->team->id]);
        $this->team->addMember($admin, Role::Admin);
        $member = User::factory()->create(['current_team_id' => $this->team->id]);
        $this->team->addMember($member, Role::Member);

        $this->actingAs($admin)->get('/audit-log/export')->assertOk();
        $this->actingAs($member)->get('/audit-log/export')->assertForbidden();
        auth()->logout();
        $this->get('/audit-log/export')->assertRedirect(route('login'));
    });

    it('is gated off below the Business plan', function () {
        $this->team->forceFill(['plan' => 'pro'])->save();

        $this->actingAs($this->owner)->get('/audit-log/export')->assertRedirect(route('billing.show'));
    });

    it('is rate limited', function () {
        foreach (range(1, 10) as $_) {
            $this->actingAs($this->owner)->get('/audit-log/export')->assertOk();
        }

        $this->actingAs($this->owner)->get('/audit-log/export')->assertTooManyRequests();
    });
});
