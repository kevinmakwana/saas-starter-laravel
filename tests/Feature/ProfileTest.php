<?php

use App\Billing\BillingGateway;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
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
            'email' => $user->email,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/profile');

    $this->assertSame('Test User', $user->refresh()->name);
});

test('a new email address waits for confirmation instead of replacing the old one', function () {
    Mail::fake();
    $user = User::factory()->create();
    $old = $user->email;

    $this->actingAs($user)->patch('/profile', ['name' => 'Test User', 'email' => 'test@example.com', 'current_password' => 'password'])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/profile');

    $user->refresh();
    $this->assertSame($old, $user->email);
    $this->assertSame('test@example.com', $user->pending_email);
    $this->assertNotNull($user->email_verified_at);
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

    it('keeps the account and explains when the subscription cannot be ended', function () {
        $gateway = new class extends RecordingGateway
        {
            public function terminate(Team $team): void
            {
                throw new RuntimeException('Stripe is down');
            }
        };
        $this->app->instance(BillingGateway::class, $gateway);
        $owner = ownerWithTeam();
        $team = $owner->currentTeam;

        $this->actingAs($owner)->from('/profile')->delete('/profile', ['password' => 'password'])
            ->assertSessionHasErrors('account')
            ->assertRedirect('/profile');

        expect($owner->fresh())->not->toBeNull()->and(Team::find($team->id))->not->toBeNull();
        $this->assertAuthenticatedAs($owner);
    });

    it('removes the Cashier subscription records together with the team', function () {
        $this->app->instance(BillingGateway::class, spyGateway());
        $owner = ownerWithTeam();
        $team = $owner->currentTeam;
        $subscriptionId = DB::table('subscriptions')->insertGetId([
            'team_id' => $team->id, 'type' => 'default', 'stripe_id' => 'sub_1', 'stripe_status' => 'active',
        ]);
        DB::table('subscription_items')->insert([
            'subscription_id' => $subscriptionId, 'stripe_id' => 'si_1', 'stripe_product' => 'prod_1', 'stripe_price' => 'price_1',
        ]);

        $this->actingAs($owner)->delete('/profile', ['password' => 'password'])->assertRedirect('/');

        expect(DB::table('subscriptions')->count())->toBe(0)->and(DB::table('subscription_items')->count())->toBe(0);
    });

    it('keeps what a leaving member wrote, without their name on it', function () {
        $this->app->instance(BillingGateway::class, spyGateway());
        $owner = ownerWithTeam();
        $team = $owner->currentTeam;
        $member = User::factory()->create(['current_team_id' => $team->id]);
        $team->addMember($member, Role::Member);
        asTenant($team, fn () => AuditLog::create(['user_id' => $member->id, 'action' => 'project.created', 'summary' => 'Created Alpha']));
        $invitation = $team->invitations()->create(['email' => 'new@example.test', 'role' => Role::Member, 'invited_by' => $member->id, 'expires_at' => now()->addDay()]);

        $this->actingAs($member)->delete('/profile', ['password' => 'password']);

        expect(asTenant($team, fn () => AuditLog::where('action', 'project.created')->first()?->user_id))->toBeNull()
            ->and($invitation->fresh()->invited_by)->toBeNull();
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
