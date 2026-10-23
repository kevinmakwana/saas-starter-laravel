<?php

use App\Enums\Role;
use App\Mail\TeamInvitationMail;
use App\Models\AuditLog;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    $this->owner = ownerWithTeam();
    $this->team = $this->owner->currentTeam;
});

function invite(string $email = 'new@example.com', string $role = 'member')
{
    test()->actingAs(test()->owner)->post('/team/invitations', ['email' => $email, 'role' => $role])->assertRedirect();

    return test()->team->invitations()->where('email', $email)->firstOrFail();
}

it('queues the invitation email on the mail queue', function () {
    invite();

    Mail::assertQueued(TeamInvitationMail::class, fn ($mail) => $mail->hasTo('new@example.com') && $mail->queue === 'mail');
});

it('rejects inviting an existing member', function () {
    $this->actingAs($this->owner)->post('/team/invitations', ['email' => $this->owner->email, 'role' => 'member'])
        ->assertSessionHasErrors('email');
});

it('re-inviting the same email refreshes instead of duplicating', function () {
    invite();
    invite();

    expect($this->team->invitations()->count())->toBe(1);
});

it('lets the invited user accept through the signed link', function () {
    $invitation = invite('new@example.com', 'admin');
    $invitee = User::factory()->create(['email' => 'new@example.com']);

    $this->actingAs($invitee)->get($invitation->acceptUrl())->assertRedirect(route('dashboard'));

    expect($this->team->roleOf($invitee))->toBe(Role::Admin)
        ->and($invitee->refresh()->current_team_id)->toBe($this->team->id)
        ->and($invitation->refresh()->accepted_at)->not->toBeNull();
});

it('rejects an unsigned or tampered link', function () {
    $invitation = invite();
    $invitee = User::factory()->create(['email' => 'new@example.com']);

    $this->actingAs($invitee)->get(route('invitations.accept', $invitation))->assertForbidden();
    $this->actingAs($invitee)->get($invitation->acceptUrl().'x')->assertForbidden();
});

it('rejects an expired link', function () {
    $invitation = invite();
    $invitee = User::factory()->create(['email' => 'new@example.com']);
    $url = $invitation->acceptUrl();

    $this->travel(8)->days();

    $this->actingAs($invitee)->get($url)->assertForbidden();
    expect($this->team->hasMember($invitee))->toBeFalse();
});

it('rejects a different signed-in user and reuse of the invitation', function () {
    $invitation = invite();
    $stranger = User::factory()->create();
    $invitee = User::factory()->create(['email' => 'new@example.com']);

    $this->actingAs($stranger)->get($invitation->acceptUrl())->assertForbidden();

    $this->actingAs($invitee)->get($invitation->acceptUrl())->assertRedirect();
    $this->actingAs($invitee)->get($invitation->acceptUrl())->assertStatus(410);
});

it('sends guests to login first', function () {
    $invitation = invite();
    $this->app['auth']->forgetGuards();

    $this->get($invitation->acceptUrl())->assertRedirect(route('login'));
});

it('lets managers cancel an invitation but not other teams', function () {
    $invitation = invite();
    $foreign = Team::factory()->create()->invitations()->create([
        'email' => 'f@example.com', 'role' => 'member', 'expires_at' => now()->addDay(),
    ]);

    $this->actingAs($this->owner)->delete("/team/invitations/{$foreign->id}")->assertNotFound();
    $this->actingAs($this->owner)->delete("/team/invitations/{$invitation->id}")->assertRedirect();
    expect($this->team->invitations()->count())->toBe(0);
});

describe('resending', function () {
    function resend(?User $as = null, ?TeamInvitation $invitation = null)
    {
        return test()->actingAs($as ?? test()->owner)->post(route('teams.invitations.resend', $invitation ?? test()->invitation));
    }

    beforeEach(function () {
        $this->invitation = invite('new@example.com');
        Mail::fake();
    });

    it('sends the email again and starts a fresh seven days', function () {
        $this->invitation->forceFill(['expires_at' => now()->addHour()])->save();

        resend()->assertSessionHasNoErrors()->assertRedirect();

        Mail::assertQueued(TeamInvitationMail::class, fn ($mail) => $mail->hasTo('new@example.com') && $mail->queue === 'mail');
        expect($this->invitation->fresh()->expires_at->isSameDay(now()->addDays(7)))->toBeTrue()
            ->and($this->team->invitations()->count())->toBe(1);
    });

    it('records the resend in the audit log', function () {
        resend();

        expect(asTenant($this->team, fn () => AuditLog::where('action', 'invitation.resent')->first()?->summary))
            ->toBe('Resent the invitation to new@example.com');
    });

    it('revives an expired invitation when the team has room', function () {
        $this->invitation->forceFill(['expires_at' => now()->subDay()])->save();

        resend()->assertSessionHasNoErrors();

        expect($this->invitation->fresh()->isPending())->toBeTrue();
        Mail::assertQueued(TeamInvitationMail::class);
    });

    it('refuses to revive an expired invitation once the plan has no seat left', function () {
        $this->team->forceFill(['plan' => 'free'])->save();
        $this->team->addMember(User::factory()->create(), Role::Member);
        $this->invitation->forceFill(['expires_at' => now()->subDay()])->save();

        resend()->assertRedirect()->assertSessionHas('upgrade');

        expect($this->invitation->fresh()->isPending())->toBeFalse();
        Mail::assertNothingQueued();
    });

    it('does not need a free seat for an invitation that already holds one', function () {
        $this->team->forceFill(['plan' => 'free'])->save();
        $this->team->addMember(User::factory()->create(), Role::Member);

        resend()->assertSessionHasNoErrors();

        Mail::assertQueued(TeamInvitationMail::class);
    });

    it('lets an admin resend a member invitation but not an admin one', function () {
        $admin = User::factory()->create(['current_team_id' => $this->team->id]);
        $this->team->addMember($admin, Role::Admin);
        $adminInvite = invite('boss@example.com', 'admin');

        resend($admin)->assertSessionHasNoErrors();
        resend($admin, $adminInvite)->assertForbidden();
    });

    it('is not available for an accepted invitation', function () {
        $this->invitation->forceFill(['accepted_at' => now()])->save();

        resend()->assertStatus(409);

        Mail::assertNothingQueued();
    });

    it('is for owners and admins of the same team only', function () {
        $member = User::factory()->create(['current_team_id' => $this->team->id]);
        $this->team->addMember($member, Role::Member);
        $stranger = ownerWithTeam();

        resend($member)->assertForbidden();
        resend($stranger)->assertNotFound();
        auth()->logout();
        test()->post(route('teams.invitations.resend', $this->invitation))->assertRedirect('/login');

        Mail::assertNothingQueued();
    });

    it('is rate limited with the other invitation actions', function () {
        foreach (range(1, 5) as $i) {
            resend();
        }

        resend()->assertStatus(429);
    });

    it('shows on the team page whether an invitation has expired', function () {
        $this->invitation->forceFill(['expires_at' => now()->subDay()])->save();

        $this->actingAs($this->owner)->get('/team')->assertInertia(fn ($page) => $page->where('invitations.0.expired', true));
    });
});
