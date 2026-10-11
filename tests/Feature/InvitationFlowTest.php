<?php

use App\Enums\Role;
use App\Mail\TeamInvitationMail;
use App\Models\Team;
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
