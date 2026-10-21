<?php

use App\Enums\Role;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Services\PlanLimits;

/** @param array<string, mixed> $attributes */
function inviteTo(Team $team, string $email, array $attributes = []): void
{
    $team->invitations()->make(array_merge(
        ['email' => $email, 'role' => Role::Member->value, 'invited_by' => $team->owner_id, 'expires_at' => now()->addDays(7)],
        $attributes,
    ))->save();
}

beforeEach(function () {
    $this->limits = new PlanLimits;
});

describe('usage', function () {
    it('counts only the projects of the given team', function () {
        $team = Team::factory()->create(['plan' => 'free']);
        $other = Team::factory()->create(['plan' => 'free']);
        asTenant($team, fn () => Project::factory()->count(2)->create());
        asTenant($other, fn () => Project::factory()->count(3)->create());

        expect($this->limits->usage($team, 'projects'))->toBe(2)
            ->and($this->limits->usage($other, 'projects'))->toBe(3);
    });

    it('counts members plus live pending invitations, not expired or accepted ones', function () {
        $team = Team::factory()->create(['plan' => 'free']);
        inviteTo($team, 'pending@example.test');
        inviteTo($team, 'expired@example.test', ['expires_at' => now()->subMinute()]);
        $accepted = $team->invitations()->make(['email' => 'done@example.test', 'role' => Role::Member->value, 'invited_by' => $team->owner_id, 'expires_at' => now()->addDays(7)]);
        $accepted->accepted_at = now();
        $accepted->save();

        expect($this->limits->usage($team, 'members'))->toBe(2);
    });

    it('is zero for a key it does not know', function () {
        expect($this->limits->usage(Team::factory()->create(), 'widgets'))->toBe(0);
    });
});

describe('check', function () {
    it('allows another project while under the limit', function () {
        $team = Team::factory()->create(['plan' => 'free']);
        asTenant($team, fn () => Project::factory()->count(2)->create());

        expect($this->limits->check($team, 'projects', 'projects')->allowed())->toBeTrue();
    });

    it('denies with a 402 and an upgrade hint once the limit is reached', function () {
        $team = Team::factory()->create(['plan' => 'free']);
        asTenant($team, fn () => Project::factory()->count(3)->create());

        $response = $this->limits->check($team, 'projects', 'projects');

        expect($response->allowed())->toBeFalse()
            ->and($response->status())->toBe(402)
            ->and($response->message())->toBe('Your Free plan includes up to 3 projects. Upgrade to Pro to add more.');
    });

    it('points at the next plan that actually has room', function () {
        config(['billing.plans.pro.limits.projects' => 3]);
        $team = Team::factory()->create(['plan' => 'free']);
        asTenant($team, fn () => Project::factory()->count(3)->create());

        expect($this->limits->check($team, 'projects', 'projects')->message())->toContain('Upgrade to Business');
    });

    it('offers a custom plan when no higher plan has room', function () {
        config(['billing.plans.business.limits.projects' => 1]);
        $team = Team::factory()->create(['plan' => 'business']);
        asTenant($team, fn () => Project::factory()->create());

        expect($this->limits->check($team, 'projects', 'projects')->message())->toContain('Contact us for a custom plan.');
    });

    it('never denies an unlimited plan', function () {
        $team = Team::factory()->create(['plan' => 'business']);
        asTenant($team, fn () => Project::factory()->count(30)->create());

        expect($this->limits->check($team, 'projects', 'projects')->allowed())->toBeTrue();
    });

    it('counts a pending invitation as a taken seat', function () {
        $team = Team::factory()->create(['plan' => 'free']);
        inviteTo($team, 'pending@example.test');

        expect($this->limits->check($team, 'members', 'members')->allowed())->toBeFalse();
    });

    it('judges by the plan in force, so a failed payment past grace uses the Free limits', function () {
        $team = Team::factory()->create(['plan' => 'business', 'payment_failed_at' => now()->subDays(10), 'payment_grace_ends_at' => now()->subDay()]);
        asTenant($team, fn () => Project::factory()->count(3)->create());

        expect($this->limits->check($team, 'projects', 'projects')->allowed())->toBeFalse();
    });
});

describe('overages', function () {
    it('is empty while the team is within its limits', function () {
        expect($this->limits->overages(Team::factory()->create(['plan' => 'free'])))->toBe([]);
    });

    it('reports each limit the team exceeds', function () {
        $team = Team::factory()->create(['plan' => 'business']);
        asTenant($team, fn () => Project::factory()->count(4)->create());
        $team->addMember(User::factory()->create(), Role::Member);
        $team->addMember(User::factory()->create(), Role::Member);
        $team->forceFill(['plan' => 'free'])->save();

        expect($this->limits->overages($team->refresh()))->toBe([
            ['key' => 'projects', 'label' => 'projects', 'used' => 4, 'limit' => 3],
            ['key' => 'members', 'label' => 'members', 'used' => 3, 'limit' => 2],
        ]);
    });

    it('does not count a pending invitation as an overage', function () {
        $team = Team::factory()->create(['plan' => 'free']);
        $team->addMember(User::factory()->create(), Role::Member);
        inviteTo($team, 'extra@example.test');

        expect($this->limits->overages($team))->toBe([]);
    });

    it('is empty at exactly the limit', function () {
        $team = Team::factory()->create(['plan' => 'free']);
        asTenant($team, fn () => Project::factory()->count(3)->create());

        expect($this->limits->overages($team))->toBe([]);
    });
});
