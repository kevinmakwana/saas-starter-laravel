<?php

use App\Billing\BillingGateway;
use App\Enums\Role;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Tests\Support\RecordingGateway;

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get('/profile');

    $response->assertOk();
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch('/profile', [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/profile');

    $user->refresh();

    $this->assertSame('Test User', $user->name);
    $this->assertSame('test@example.com', $user->email);
    $this->assertNull($user->email_verified_at);
});

test('email verification status is unchanged when the email address is unchanged', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch('/profile', [
            'name' => 'Test User',
            'email' => $user->email,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/profile');

    $this->assertNotNull($user->refresh()->email_verified_at);
});

test('user can delete their account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->delete('/profile', [
            'password' => 'password',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/');

    $this->assertGuest();
    $this->assertNull($user->fresh());
});

test('correct password must be provided to delete account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from('/profile')
        ->delete('/profile', [
            'password' => 'wrong-password',
        ]);

    $response
        ->assertSessionHasErrors('password')
        ->assertRedirect('/profile');

    $this->assertNotNull($user->fresh());
});

describe('account deletion and owned teams', function () {
    function spyGateway(): RecordingGateway
    {
        return new RecordingGateway;
    }

    it('refuses to delete an owner whose team still has other members', function () {
        $owner = ownerWithTeam();
        $team = $owner->currentTeam;
        $team->addMember(User::factory()->create(), Role::Member);
        asTenant($team, fn () => Project::factory()->create());

        $this->actingAs($owner)
            ->from('/profile')
            ->delete('/profile', ['password' => 'password'])
            ->assertSessionHasErrors('account')
            ->assertRedirect('/profile');

        expect($owner->fresh())->not->toBeNull()
            ->and(Team::find($team->id))->not->toBeNull()
            ->and($team->users()->count())->toBe(2);
        $this->assertAuthenticatedAs($owner);
    });

    it('deletes teams the user owns alone and terminates their billing first', function () {
        $gateway = spyGateway();
        $this->app->instance(BillingGateway::class, $gateway);
        $owner = ownerWithTeam();
        $team = $owner->currentTeam;

        $this->actingAs($owner)->delete('/profile', ['password' => 'password'])->assertRedirect('/');

        expect($owner->fresh())->toBeNull()
            ->and(Team::find($team->id))->toBeNull()
            ->and($gateway->terminated)->toBe([$team->id]);
    });

    it('lets a plain member delete their account without touching the team', function () {
        $gateway = spyGateway();
        $this->app->instance(BillingGateway::class, $gateway);
        $owner = ownerWithTeam();
        $team = $owner->currentTeam;
        $member = User::factory()->create(['current_team_id' => $team->id]);
        $team->addMember($member, Role::Member);

        $this->actingAs($member)->delete('/profile', ['password' => 'password'])->assertRedirect('/');

        expect($member->fresh())->toBeNull()
            ->and(Team::find($team->id))->not->toBeNull()
            ->and($team->users()->count())->toBe(1)
            ->and($gateway->terminated)->toBe([]);
    });
});
