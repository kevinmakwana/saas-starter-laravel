<?php

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

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
