<?php

use App\Billing\BillingGateway;
use App\Enums\Role;
use App\Mail\TeamDeletedMail;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\Support\RecordingGateway;

beforeEach(function () {
    Mail::fake();
    $this->gateway = new RecordingGateway;
    $this->app->instance(BillingGateway::class, $this->gateway);
    $this->owner = ownerWithTeam();
    $this->team = $this->owner->currentTeam;
});

function deleteTeam(User $as, array $input = []): TestResponse
{
    return test()->actingAs($as)->from('/team')->delete('/team', array_merge(['password' => 'password', 'name' => test()->team->name], $input));
}

function memberOf(Team $team, Role $role = Role::Member): User
{
    $user = User::factory()->create(['current_team_id' => $team->id]);
    $team->addMember($user, $role);

    return $user;
}

it('lets the owner delete the team with everything in it', function () {
    asTenant($this->team, fn () => Project::factory()->count(2)->create());
    $this->team->invitations()->create(['email' => 'new@example.test', 'role' => Role::Member, 'invited_by' => $this->owner->id, 'expires_at' => now()->addDay()]);
    $other = Team::factory()->create();
    $kept = asTenant($other, fn () => Project::factory()->create());

    deleteTeam($this->owner)->assertSessionHasNoErrors();

    expect(Team::find($this->team->id))->toBeNull()
        ->and(DB::table('projects')->where('tenant_id', $this->team->id)->count())->toBe(0)
        ->and(DB::table('team_invitations')->where('team_id', $this->team->id)->count())->toBe(0)
        ->and(DB::table('team_user')->where('team_id', $this->team->id)->count())->toBe(0)
        ->and(Team::find($other->id))->not->toBeNull()
        ->and(DB::table('projects')->where('id', $kept->id)->exists())->toBeTrue();
});

it('ends the team billing before deleting anything and removes the Cashier records', function () {
    $subscriptionId = DB::table('subscriptions')->insertGetId([
        'team_id' => $this->team->id, 'type' => 'default', 'stripe_id' => 'sub_1', 'stripe_status' => 'active',
    ]);
    DB::table('subscription_items')->insert([
        'subscription_id' => $subscriptionId, 'stripe_id' => 'si_1', 'stripe_product' => 'prod_1', 'stripe_price' => 'price_1',
    ]);

    deleteTeam($this->owner);

    expect($this->gateway->terminated)->toBe([$this->team->id])
        ->and(DB::table('subscriptions')->count())->toBe(0)
        ->and(DB::table('subscription_items')->count())->toBe(0);
});

it('sends the owner to another team of theirs, or to create one', function () {
    $second = Team::factory()->create(['owner_id' => $this->owner->id]);
    $second->addMember($this->owner, Role::Owner);

    deleteTeam($this->owner)->assertRedirect(route('dashboard'));
    expect($this->owner->fresh()->current_team_id)->toBe($second->id);

    $alone = ownerWithTeam();
    $this->team = $alone->currentTeam;
    deleteTeam($alone)->assertRedirect(route('teams.create'));
    expect($alone->fresh()->current_team_id)->toBeNull();
});

it('leaves the members on their other teams, or without a team', function () {
    $elsewhere = Team::factory()->create();
    $withOther = memberOf($this->team);
    $elsewhere->addMember($withOther, Role::Member);
    $withoutOther = memberOf($this->team);

    deleteTeam($this->owner);

    expect(User::find($withOther->id))->not->toBeNull()
        ->and($withOther->fresh()->current_team_id)->toBeNull()
        ->and($withOther->teams()->pluck('teams.id')->all())->toBe([$elsewhere->id]);

    $this->actingAs($withoutOther)->get('/dashboard')->assertRedirect(route('teams.create'));
    $this->actingAs($withOther)->get('/dashboard')->assertOk();
});

it('tells every other member by email, naming the team and who deleted it', function () {
    $one = memberOf($this->team);
    $two = memberOf($this->team, Role::Admin);

    deleteTeam($this->owner);

    Mail::assertQueued(TeamDeletedMail::class, 2);
    Mail::assertQueued(TeamDeletedMail::class, fn (TeamDeletedMail $mail) => $mail->hasTo($one->email)
        && $mail->teamName === $this->team->name
        && $mail->deletedBy === $this->owner->name
        && $mail->queue === 'mail');
    Mail::assertQueued(TeamDeletedMail::class, fn (TeamDeletedMail $mail) => $mail->hasTo($two->email));
    Mail::assertNotQueued(TeamDeletedMail::class, fn (TeamDeletedMail $mail) => $mail->hasTo($this->owner->email));
});

it('renders the notification without needing the deleted team', function () {
    $mail = new TeamDeletedMail('Acme Inc', 'Ada');

    $mail->assertSeeInHtml('Acme Inc');
    $mail->assertSeeInHtml('Ada');
    expect($mail->envelope()->subject)->toBe('Acme Inc was deleted');
});

describe('refusals', function () {
    it('needs the correct password', function () {
        deleteTeam($this->owner, ['password' => 'wrong'])->assertSessionHasErrors('password');

        expect(Team::find($this->team->id))->not->toBeNull()->and($this->gateway->terminated)->toBe([]);
    });

    it('needs the team name typed exactly', function () {
        deleteTeam($this->owner, ['name' => $this->team->name.'x'])->assertSessionHasErrors('name');
        deleteTeam($this->owner, ['name' => ''])->assertSessionHasErrors('name');

        expect(Team::find($this->team->id))->not->toBeNull()->and($this->gateway->terminated)->toBe([]);
    });

    it('is for the owner only', function () {
        foreach ([Role::Admin, Role::Member] as $role) {
            deleteTeam(memberOf($this->team, $role))->assertForbidden();
        }

        expect(Team::find($this->team->id))->not->toBeNull();
        Mail::assertNothingQueued();
    });

    it('is for signed-in users only', function () {
        $this->delete('/team', ['password' => 'password', 'name' => $this->team->name])->assertRedirect('/login');

        expect(Team::find($this->team->id))->not->toBeNull();
    });

    it('keeps everything and says so when the subscription cannot be ended', function () {
        $this->app->instance(BillingGateway::class, new class extends RecordingGateway
        {
            public function terminate(Team $team): void
            {
                throw new RuntimeException('Stripe is down');
            }
        });
        memberOf($this->team);

        deleteTeam($this->owner)->assertSessionHasErrors('team')->assertRedirect('/team');

        expect(Team::find($this->team->id))->not->toBeNull()
            ->and($this->team->users()->count())->toBe(2);
        Mail::assertNothingQueued();
    });

    it('only ever deletes the team the owner is working in', function () {
        $other = ownerWithTeam();

        deleteTeam($this->owner);

        expect(Team::find($other->currentTeam->id))->not->toBeNull();
    });
});

it('offers deletion on the team page to the owner only', function () {
    $admin = memberOf($this->team, Role::Admin);

    $this->actingAs($this->owner)->get('/team')->assertInertia(fn ($page) => $page->where('can.delete', true));
    $this->actingAs($admin)->get('/team')->assertInertia(fn ($page) => $page->where('can.delete', false));
});
