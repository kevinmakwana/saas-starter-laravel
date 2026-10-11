<?php

use App\Billing\BillingGateway;
use App\Billing\FakeGateway;
use App\Billing\StripeGateway;
use App\Enums\Plan;
use App\Enums\Role;
use App\Models\User;
use App\Services\FeatureGate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->owner = ownerWithTeam('free');
    $this->team = $this->owner->currentTeam;
});

it('runs on the fake gateway when no Stripe keys are set', function () {
    expect(app(BillingGateway::class))->toBeInstanceOf(FakeGateway::class);
});

it('selects the Stripe gateway only when configured for it and a secret is present', function () {
    config(['billing.gateway' => 'stripe', 'cashier.secret' => null]);
    expect(app(BillingGateway::class))->toBeInstanceOf(FakeGateway::class);

    config(['billing.gateway' => 'fake', 'cashier.secret' => 'sk_test_x']);
    expect(app(BillingGateway::class))->toBeInstanceOf(FakeGateway::class);

    config(['billing.gateway' => 'stripe', 'cashier.secret' => 'sk_test_x']);
    expect(app(BillingGateway::class))->toBeInstanceOf(StripeGateway::class);
});

it('subscribes, changes plan and cancels with the fake gateway', function () {
    $this->actingAs($this->owner)->post('/billing/subscribe', ['plan' => 'pro'])->assertRedirect();
    expect($this->team->refresh()->currentPlan())->toBe(Plan::Pro);

    $this->actingAs($this->owner)->post('/billing/change', ['plan' => 'business'])->assertRedirect();
    expect($this->team->refresh()->currentPlan())->toBe(Plan::Business);

    $this->actingAs($this->owner)->post('/billing/cancel')->assertRedirect();
    expect($this->team->refresh()->currentPlan())->toBe(Plan::Business)
        ->and($this->team->onGracePeriod())->toBeTrue();

    $this->travel(31)->days();
    expect($this->team->refresh()->currentPlan())->toBe(Plan::Free);
});

it('resumes a cancelled subscription', function () {
    $this->team->forceFill(['plan' => 'pro', 'plan_ends_at' => now()->addDays(5)])->save();

    $this->actingAs($this->owner)->post('/billing/resume')->assertRedirect();

    expect($this->team->refresh()->onGracePeriod())->toBeFalse()->and($this->team->currentPlan())->toBe(Plan::Pro);
});

it('rejects subscribing to the free plan or an unknown plan', function () {
    $this->actingAs($this->owner)->post('/billing/subscribe', ['plan' => 'free'])->assertSessionHasErrors('plan');
    $this->actingAs($this->owner)->post('/billing/subscribe', ['plan' => 'platinum'])->assertSessionHasErrors('plan');
});

it('lets only the owner manage billing', function () {
    $admin = User::factory()->create(['current_team_id' => $this->team->id]);
    $this->team->addMember($admin, Role::Admin);

    $this->actingAs($admin)->get('/billing')->assertOk();
    $this->actingAs($admin)->post('/billing/subscribe', ['plan' => 'pro'])->assertForbidden();
    $this->actingAs($admin)->post('/billing/cancel')->assertForbidden();
});

it('enforces the member limit with an upgrade prompt', function () {
    Mail::fake();
    // Free allows 2 members: the owner plus one pending invitation fills it.
    $this->actingAs($this->owner)->post('/team/invitations', ['email' => 'a@example.com', 'role' => 'member'])->assertRedirect()
        ->assertSessionMissing('upgrade');

    $this->actingAs($this->owner)->post('/team/invitations', ['email' => 'b@example.com', 'role' => 'member'])
        ->assertRedirect()
        ->assertSessionHas('upgrade', fn ($msg) => str_contains($msg, 'Free plan includes up to 2 members') && str_contains($msg, 'Upgrade to Pro'));

    expect($this->team->invitations()->count())->toBe(1);

    // Re-inviting an already pending address needs no extra seat.
    $this->actingAs($this->owner)->post('/team/invitations', ['email' => 'a@example.com', 'role' => 'member'])->assertSessionMissing('upgrade');

    $this->team->forceFill(['plan' => 'pro'])->save();
    $this->actingAs($this->owner)->post('/team/invitations', ['email' => 'b@example.com', 'role' => 'member'])->assertSessionMissing('upgrade');
    expect($this->team->invitations()->count())->toBe(2);
});

it('exposes feature flags per plan', function (string $plan, bool $audit, bool $export) {
    $this->team->forceFill(['plan' => $plan])->save();
    $gate = app(FeatureGate::class);

    expect($gate->allows($this->team, 'audit_log'))->toBe($audit)
        ->and($gate->allows($this->team, 'task_export'))->toBe($export)
        ->and($gate->allows($this->team, 'unknown_feature'))->toBeFalse();
})->with([
    ['free', false, false],
    ['pro', false, true],
    ['business', true, true],
]);

it('treats an expired cancelled plan as free for feature gates', function () {
    $this->team->forceFill(['plan' => 'business', 'plan_ends_at' => now()->subDay()])->save();

    expect(app(FeatureGate::class)->allows($this->team, 'audit_log'))->toBeFalse();
});

it('redirects to billing when a gated feature is requested without the plan', function () {
    Route::middleware(['web', 'auth', 'team', 'feature:audit_log'])->get('/_gated', fn () => 'secret');

    $this->actingAs($this->owner)->get('/_gated')->assertRedirect(route('billing.show'))->assertSessionHas('upgrade');

    $this->team->forceFill(['plan' => 'business'])->save();
    $this->actingAs($this->owner)->get('/_gated')->assertOk()->assertSee('secret');
});
