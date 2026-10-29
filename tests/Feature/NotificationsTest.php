<?php

use App\Billing\BillingGateway;
use App\Enums\Role;
use App\Models\Team;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Support\RecordingGateway;

beforeEach(function () {
    Mail::fake();
    $this->owner = ownerWithTeam();
    $this->team = $this->owner->currentTeam;
});

function member(Team $team, Role $role = Role::Member): User
{
    $user = User::factory()->create(['current_team_id' => $team->id]);
    $team->addMember($user, $role);

    return $user;
}

/** @return list<string> the kinds of the user's notifications, oldest first */
function kindsOf(User $user): array
{
    return $user->notifications()->reorder('created_at')->get()->map(fn (DatabaseNotification $n) => $n->data['kind'])->all();
}

function notify(User $user, array $data = [], ?string $readAt = null): DatabaseNotification
{
    return $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => 'App\\Notifications\\Test',
        'data' => array_merge(['kind' => 'plan.changed', 'params' => ['team' => 'Acme', 'plan' => 'Pro'], 'team_id' => null, 'url' => '/billing'], $data),
        'read_at' => $readAt,
    ]);
}

describe('what triggers one', function () {
    it('tells the owner and admins, but not the newcomer or other members, when someone joins', function () {
        $admin = member($this->team, Role::Admin);
        $plain = member($this->team);
        $invitation = $this->team->invitations()->create(['email' => 'new@example.test', 'role' => Role::Member, 'invited_by' => $this->owner->id, 'expires_at' => now()->addDay()]);
        $newcomer = User::factory()->create(['email' => 'new@example.test']);

        $this->actingAs($newcomer)->get($invitation->acceptUrl())->assertRedirect(route('dashboard'));

        $joined = $this->owner->notifications()->first();
        expect(kindsOf($this->owner))->toBe(['member.joined'])
            ->and(kindsOf($admin))->toBe(['member.joined'])
            ->and(kindsOf($plain))->toBe([])
            ->and(kindsOf($newcomer))->toBe([])
            ->and($joined->data)->toMatchArray(['team_id' => $this->team->id, 'url' => '/team', 'params' => ['name' => $newcomer->name, 'team' => $this->team->name]]);
    });

    it('tells a member who was removed, but not one who left on their own', function () {
        $removed = member($this->team);
        $leaver = member($this->team);

        $this->actingAs($this->owner)->delete("/team/members/{$removed->id}")->assertRedirect();
        $this->actingAs($leaver)->delete("/team/members/{$leaver->id}")->assertRedirect();

        expect(kindsOf($removed))->toBe(['member.removed'])
            ->and($removed->notifications()->first()->data)->toMatchArray(['team_id' => null, 'url' => '/dashboard', 'params' => ['team' => $this->team->name]])
            ->and(kindsOf($leaver))->toBe([])
            ->and(kindsOf($this->owner))->toBe([]);
    });

    it('tells the other members, not the owner, when the team is deleted', function () {
        $this->app->instance(BillingGateway::class, new RecordingGateway);
        $other = member($this->team);

        $this->actingAs($this->owner)->delete('/team', ['password' => 'password', 'name' => $this->team->name])->assertRedirect();

        expect(kindsOf($other))->toBe(['team.deleted'])
            ->and($other->notifications()->first()->data['params'])->toBe(['team' => $this->team->name, 'by' => $this->owner->name])
            ->and($other->notifications()->first()->data['team_id'])->toBeNull()
            ->and(kindsOf($this->owner))->toBe([]);
    });

    it('tells the owner when the plan changes, and nobody else', function () {
        $admin = member($this->team, Role::Admin);

        $this->team->forceFill(['plan' => 'pro'])->save();

        $n = $this->owner->notifications()->first();
        expect(kindsOf($this->owner))->toBe(['plan.changed'])
            ->and($n->data)->toMatchArray(['team_id' => $this->team->id, 'url' => '/billing', 'params' => ['team' => $this->team->name, 'plan' => 'Pro']])
            ->and(kindsOf($admin))->toBe([]);
    });

    it('does not tell anyone about a save that leaves the plan alone', function () {
        $this->team->forceFill(['name' => 'Renamed'])->save();

        expect(kindsOf($this->owner))->toBe([]);
    });

    it('is only an in-app note: the emails the app already sends are unchanged', function () {
        $this->team->forceFill(['plan' => 'pro'])->save();

        Mail::assertNothingQueued();
    });
});

describe('the bell', function () {
    it('shares the unread count and the newest ten with every page', function () {
        foreach (range(1, 12) as $i) {
            notify($this->owner, ['params' => ['team' => "T{$i}", 'plan' => 'Pro']])->forceFill(['created_at' => now()->addMinutes($i)])->save();
        }
        $this->owner->notifications()->reorder('created_at')->first()->markAsRead();

        $this->actingAs($this->owner)->get('/dashboard')->assertInertia(fn ($page) => $page
            ->where('notifications.unread', 11)
            ->has('notifications.items', 10)
            ->where('notifications.items.0.params.team', 'T12')
            ->where('notifications.items.0.read', false)
            ->has('notifications.items.0', fn ($item) => $item->hasAll(['id', 'kind', 'params', 'read', 'created_at']))
            ->where('notifications.items.9.params.team', 'T3'));
    });

    it('is empty for a user with none, and absent for guests', function () {
        $this->actingAs($this->owner)->get('/dashboard')->assertInertia(fn ($page) => $page->where('notifications.unread', 0)->where('notifications.items', []));

        auth()->logout();
        $this->get('/login')->assertInertia(fn ($page) => $page->where('notifications', null));
    });

    it('only ever shows the signed-in user their own', function () {
        $other = member($this->team);
        notify($other, ['params' => ['team' => 'Theirs', 'plan' => 'Pro']]);

        $this->actingAs($this->owner)->get('/dashboard')->assertInertia(fn ($page) => $page->where('notifications.unread', 0));
    });

    it('can be refreshed on its own, for polling', function () {
        notify($this->owner);

        $this->actingAs($this->owner)->get('/dashboard')->assertInertia(fn ($page) => $page
            ->reloadOnly('notifications', fn ($reload) => $reload->where('notifications.unread', 1)->has('notifications.items', 1)));
    });
});

describe('opening one', function () {
    it('marks it read, goes to its page, and switches to its team', function () {
        $second = Team::factory()->create();
        $second->addMember($this->owner, Role::Owner);
        $n = notify($this->owner, ['team_id' => $second->id, 'url' => '/billing']);

        $this->actingAs($this->owner)->post("/notifications/{$n->id}/open")->assertRedirect('/billing');

        expect($n->fresh()->read_at)->not->toBeNull()
            ->and($this->owner->fresh()->current_team_id)->toBe($second->id);
    });

    it('stays in the current team when the notification is not about a team', function () {
        $n = notify($this->owner, ['team_id' => null, 'url' => '/dashboard']);

        $this->actingAs($this->owner)->post("/notifications/{$n->id}/open")->assertRedirect('/dashboard');

        expect($this->owner->fresh()->current_team_id)->toBe($this->team->id);
    });

    it('does not switch into a team the user no longer belongs to', function () {
        $gone = Team::factory()->create();
        $n = notify($this->owner, ['team_id' => $gone->id, 'url' => '/team']);

        $this->actingAs($this->owner)->post("/notifications/{$n->id}/open")->assertRedirect('/team');

        expect($this->owner->fresh()->current_team_id)->toBe($this->team->id);
    });

    it('never redirects anywhere but a page of this app', function (string $url) {
        $n = notify($this->owner, ['url' => $url]);

        $this->actingAs($this->owner)->post("/notifications/{$n->id}/open")->assertRedirect('/dashboard');
    })->with(['https://evil.example/phish', '//evil.example', 'javascript:alert(1)', '']);

    it('is not possible for someone else\'s notification', function () {
        $other = member($this->team);
        $n = notify($other);

        $this->actingAs($this->owner)->post("/notifications/{$n->id}/open")->assertNotFound();

        expect($n->fresh()->read_at)->toBeNull();
    });

    it('is not possible for a notification that does not exist', function () {
        $this->actingAs($this->owner)->post('/notifications/nope/open')->assertNotFound();
    });

    it('is for signed-in users only', function () {
        $n = notify($this->owner);

        $this->post("/notifications/{$n->id}/open")->assertRedirect('/login');
        $this->post('/notifications/read-all')->assertRedirect('/login');
    });
});

describe('marking all read', function () {
    it('marks every one of the user\'s, and nobody else\'s', function () {
        $other = member($this->team);
        notify($this->owner);
        notify($this->owner);
        notify($other);

        $this->actingAs($this->owner)->from('/dashboard')->post('/notifications/read-all')->assertRedirect('/dashboard');

        expect($this->owner->unreadNotifications()->count())->toBe(0)
            ->and($other->unreadNotifications()->count())->toBe(1);
    });
});

describe('housekeeping', function () {
    it('prunes old notifications after the retention period, read or not', function () {
        config(['retention.notifications_days' => 60]);
        $old = notify($this->owner);
        $old->forceFill(['created_at' => now()->subDays(61)])->save();
        $recent = notify($this->owner);
        $recent->forceFill(['created_at' => now()->subDays(59)])->save();

        $this->artisan('notifications:prune')->assertSuccessful();

        expect(DatabaseNotification::pluck('id')->all())->toBe([$recent->id]);
    });

    it('is on the daily schedule', function () {
        $scheduled = collect(app(Schedule::class)->events())->map->command;

        expect($scheduled->filter(fn ($c) => str_contains((string) $c, 'notifications:prune')))->not->toBeEmpty();
    });

    it('goes away with the account', function () {
        notify($this->owner);
        $solo = ownerWithTeam();
        notify($solo);

        $this->actingAs($solo)->delete('/profile', ['password' => 'password'])->assertRedirect('/');

        expect(DB::table('notifications')->count())->toBe(1);
    });

    it('is part of the personal data download', function () {
        notify($this->owner, ['params' => ['team' => 'Acme', 'plan' => 'Pro']]);

        $json = $this->actingAs($this->owner)->from('/profile')->post('/profile/export', ['password' => 'password'])->streamedContent();
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        expect($data['notifications'])->toHaveCount(1)
            ->and(array_keys($data['notifications'][0]))->toBe(['kind', 'params', 'read_at', 'created_at'])
            ->and($data['notifications'][0]['kind'])->toBe('plan.changed');
    });
});
