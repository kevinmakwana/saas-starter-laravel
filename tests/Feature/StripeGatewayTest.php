<?php

use App\Billing\StripeGateway;
use App\Enums\Plan;
use App\Models\Team;
use Laravel\Cashier\Cashier;

/*
| These run the real StripeGateway and Cashier against stripe-mock (https://github.com/stripe/stripe-mock),
| Stripe's official mock server, which validates every request against Stripe's OpenAPI spec. No keys needed.
|
|   docker run --rm -p 12111:12111 stripe/stripe-mock
|   STRIPE_MOCK_URL=http://127.0.0.1:12111 vendor/bin/pest --group=stripe-mock
|
| Skipped when STRIPE_MOCK_URL is not set. CI sets it. stripe-mock returns canned fixtures, so these tests prove the
| requests are valid and the local bookkeeping works, not what Stripe would answer for your real prices.
*/
pest()->group('stripe-mock');

beforeEach(function () {
    $url = getenv('STRIPE_MOCK_URL');
    if (! $url) {
        $this->markTestSkipped('Set STRIPE_MOCK_URL to run against stripe-mock.');
    }

    config([
        'cashier.secret' => 'sk_test_mock',
        'billing.plans.pro.stripe_price' => 'price_pro',
        'billing.plans.business.stripe_price' => 'price_biz',
    ]);
    Cashier::$apiBaseUrl = $url;

    $this->gateway = new StripeGateway;
    $this->team = ownerWithTeam('free')->currentTeam;
});

afterEach(fn () => Cashier::$apiBaseUrl = 'https://api.stripe.com');

/** A team that already has a subscription row, as the webhook flow would have left it. */
function subscribed(Team $team, array $attributes = []): Team
{
    $team->forceFill(['stripe_id' => 'cus_mock', 'plan' => 'pro'])->save();
    $subscription = $team->subscriptions()->create(array_merge([
        'type' => 'default', 'stripe_id' => 'sub_mock', 'stripe_status' => 'active',
        'stripe_price' => 'price_pro', 'quantity' => 1,
    ], $attributes));
    $subscription->items()->create(['stripe_id' => 'si_mock', 'stripe_product' => 'prod_mock', 'stripe_price' => 'price_pro', 'quantity' => 1]);

    return $team->refresh();
}

it('starts a Checkout session and creates the Stripe customer', function () {
    $url = $this->gateway->subscribe($this->team, Plan::Pro);

    expect($url)->toBeString()->toStartWith('https://')
        ->and($this->team->refresh()->stripe_id)->toStartWith('cus_');
});

it('refuses to start Checkout for a plan without a configured price', function () {
    config(['billing.plans.pro.stripe_price' => null]);

    expect(fn () => $this->gateway->subscribe($this->team, Plan::Pro))->toThrow(RuntimeException::class, 'No Stripe price');
});

it('swaps the subscription to another plan and records it locally', function () {
    $team = subscribed($this->team);

    $this->gateway->changePlan($team, Plan::Business);

    expect($team->refresh()->plan)->toBe('business')->and($team->plan_ends_at)->toBeNull();
});

it('fails clearly when there is no subscription to change', function () {
    expect(fn () => $this->gateway->changePlan($this->team, Plan::Business))->toThrow(RuntimeException::class, 'no active subscription');
});

it('cancels at the end of the period and remembers when access ends', function () {
    $team = subscribed($this->team);

    $this->gateway->cancel($team);

    expect($team->refresh()->plan_ends_at)->not->toBeNull()
        ->and($team->subscription('default')->ends_at)->not->toBeNull();
});

it('resumes a subscription that is still in its grace period', function () {
    $team = subscribed($this->team, ['ends_at' => now()->addDays(5)]);
    $team->forceFill(['plan_ends_at' => now()->addDays(5)])->save();

    $this->gateway->resume($team);

    expect($team->refresh()->plan_ends_at)->toBeNull();
});

it('ends the subscription immediately when the team is being deleted', function () {
    $team = subscribed($this->team);

    $this->gateway->terminate($team);

    expect($team->subscription('default')->ends_at)->not->toBeNull()
        ->and($team->subscription('default')->ends_at->isPast() || $team->subscription('default')->ends_at->isToday())->toBeTrue();
});

it('does nothing when terminating a team that never subscribed', function () {
    $this->gateway->terminate($this->team);

    expect($this->team->refresh()->stripe_id)->toBeNull();
});

it('creates a Customer Portal session for an existing customer', function () {
    $team = subscribed($this->team);

    expect($this->gateway->portalUrl($team, 'https://app.test/billing'))->toBeString()->toStartWith('https://');
});

it('lists no invoices for a team without a Stripe customer', function () {
    expect($this->gateway->invoices($this->team))->toBe([]);
});

it('lists invoices from Stripe and hides drafts', function () {
    // stripe-mock's canned invoice is a draft that belongs to this customer (Cashier checks ownership).
    $team = subscribed($this->team);
    $team->forceFill(['stripe_id' => 'cus_QXg1o8vcGmoR32'])->save();

    expect($this->gateway->invoices($team->refresh()))->toBe([]);
});
