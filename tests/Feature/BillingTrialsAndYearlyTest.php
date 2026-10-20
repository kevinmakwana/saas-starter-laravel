<?php

use App\Billing\BillingGateway;
use App\Enums\BillingInterval;
use App\Enums\Plan;
use App\Mail\TrialEndingMail;
use Tests\Support\RecordingGateway;

beforeEach(function () {
    config([
        'billing.plans.pro.stripe_price' => 'price_pro',
        'billing.plans.pro.stripe_price_yearly' => 'price_pro_year',
        'billing.plans.business.stripe_price' => 'price_biz',
        'billing.plans.business.stripe_price_yearly' => 'price_biz_year',
    ]);
    $this->gateway = new RecordingGateway;
    $this->gateway->offersYearly = true;
    $this->app->instance(BillingGateway::class, $this->gateway);

    $this->owner = ownerWithTeam('free');
    $this->team = $this->owner->currentTeam;
});

/** A team with a Stripe subscription row, the way the webhook flow leaves it. */
function withSubscription($team, string $price, string $status = 'active', array $attributes = []): void
{
    $team->forceFill(['stripe_id' => 'cus_1'])->save();
    $subscription = $team->subscriptions()->create(array_merge([
        'type' => 'default', 'stripe_id' => 'sub_1', 'stripe_status' => $status, 'stripe_price' => $price, 'quantity' => 1,
    ], $attributes));
    $subscription->items()->create(['stripe_id' => 'si_1', 'stripe_product' => 'prod_1', 'stripe_price' => $price, 'quantity' => 1]);
}

describe('choosing a billing interval', function () {
    it('defaults to monthly when none is sent', function () {
        $this->actingAs($this->owner)->post('/billing/subscribe', ['plan' => 'pro'])->assertRedirect();

        expect($this->gateway->calls)->toBe([['subscribe', Plan::Pro, BillingInterval::Monthly]]);
    });

    it('subscribes yearly', function () {
        $this->actingAs($this->owner)->post('/billing/subscribe', ['plan' => 'pro', 'interval' => 'yearly'])->assertRedirect();

        expect($this->gateway->calls)->toBe([['subscribe', Plan::Pro, BillingInterval::Yearly]]);
    });

    it('rejects an unknown interval', function () {
        $this->actingAs($this->owner)->post('/billing/subscribe', ['plan' => 'pro', 'interval' => 'weekly'])->assertSessionHasErrors('interval');

        expect($this->gateway->calls)->toBe([]);
    });

    it('refuses yearly billing the gateway cannot offer, and charges nothing', function () {
        $this->gateway->offersYearly = false;

        $this->actingAs($this->owner)->from('/billing')->post('/billing/subscribe', ['plan' => 'pro', 'interval' => 'yearly'])
            ->assertRedirect('/billing')->assertSessionHas('error', fn ($m) => str_contains($m, 'Yearly billing'));

        expect($this->gateway->calls)->toBe([]);
    });

    it('refuses yearly billing for a plan without a yearly price', function () {
        config(['billing.plans.pro.yearly_price' => null]);

        $this->actingAs($this->owner)->from('/billing')->post('/billing/subscribe', ['plan' => 'pro', 'interval' => 'yearly'])
            ->assertRedirect('/billing')->assertSessionHas('error');

        expect($this->gateway->calls)->toBe([]);
    });

    it('switches a monthly subscription to yearly on the same plan', function () {
        $this->team->forceFill(['plan' => 'pro'])->save();
        withSubscription($this->team, 'price_pro');

        $this->actingAs($this->owner)->post('/billing/change', ['plan' => 'pro', 'interval' => 'yearly'])
            ->assertRedirect()->assertSessionHas('success');

        expect($this->gateway->calls)->toBe([['changePlan', Plan::Pro, BillingInterval::Yearly]]);
    });

    it('still refuses a change to the same plan and the same interval', function () {
        $this->team->forceFill(['plan' => 'pro'])->save();
        withSubscription($this->team, 'price_pro_year');

        $this->actingAs($this->owner)->post('/billing/change', ['plan' => 'pro', 'interval' => 'yearly'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'already on'));

        expect($this->gateway->calls)->toBe([]);
    });

    it('keeps the current interval when only the plan is sent', function () {
        $this->team->forceFill(['plan' => 'pro'])->save();
        withSubscription($this->team, 'price_pro_year');

        $this->actingAs($this->owner)->post('/billing/change', ['plan' => 'business'])->assertRedirect();

        expect($this->gateway->calls)->toBe([['changePlan', Plan::Business, BillingInterval::Yearly]]);
    });
});

describe('the billing page', function () {
    it('offers yearly prices and a trial to a team that never subscribed', function () {
        config(['billing.trial_days' => 14]);
        $this->gateway->trialDays = 14;

        $this->actingAs($this->owner)->get('/billing')->assertInertia(fn ($page) => $page
            ->where('trialDays', 14)
            ->where('trialEndsAt', null)
            ->where('interval', 'monthly')
            ->where('plans.1.yearlyPrice', 290)
            ->where('plans.2.yearlyPrice', 990)
            ->where('plans.0.yearlyPrice', null));
    });

    it('hides the yearly price when the gateway cannot bill yearly', function () {
        $this->gateway->offersYearly = false;

        $this->actingAs($this->owner)->get('/billing')
            ->assertInertia(fn ($page) => $page->where('plans.1.yearlyPrice', null));
    });

    it('offers no trial to a team that is already on a paid plan', function () {
        $this->gateway->trialDays = 14;
        $this->team->forceFill(['plan' => 'pro'])->save();

        $this->actingAs($this->owner)->get('/billing')->assertInertia(fn ($page) => $page->where('trialDays', 0));
    });

    it('shows the current interval and when the trial ends', function () {
        $end = now()->addDays(9)->startOfSecond();
        $this->team->forceFill(['plan' => 'pro'])->save();
        withSubscription($this->team, 'price_pro_year', 'trialing', ['trial_ends_at' => $end]);

        $this->actingAs($this->owner)->get('/billing')->assertInertia(fn ($page) => $page
            ->where('interval', 'yearly')
            ->where('trialEndsAt', $end->toIso8601String()));
    });

    it('shows no trial once it is over', function () {
        $this->team->forceFill(['plan' => 'pro'])->save();
        withSubscription($this->team, 'price_pro', 'active', ['trial_ends_at' => now()->subDay()]);

        $this->actingAs($this->owner)->get('/billing')->assertInertia(fn ($page) => $page->where('trialEndsAt', null));
    });
});

it('renders the trial ending email with the date and a link to the billing page', function () {
    $this->team->forceFill(['plan' => 'pro'])->save();
    $mail = new TrialEndingMail($this->team, now()->addDays(3));

    $mail->assertSeeInHtml($this->team->name)
        ->assertSeeInHtml(now()->addDays(3)->toFormattedDateString())
        ->assertSeeInHtml(route('billing.show'));
});
