<?php

use App\Enums\BillingInterval;
use App\Enums\Plan;

beforeEach(fn () => config([
    'billing.plans.pro.stripe_price' => 'price_pro',
    'billing.plans.pro.stripe_price_yearly' => 'price_pro_year',
    'billing.plans.business.stripe_price' => 'price_biz',
    'billing.plans.business.stripe_price_yearly' => null,
]));

it('prices a yearly plan separately from a monthly one', function () {
    expect(Plan::Pro->price())->toBe(29)
        ->and(Plan::Pro->price(BillingInterval::Monthly))->toBe(29)
        ->and(Plan::Pro->price(BillingInterval::Yearly))->toBe(290)
        ->and(Plan::Business->price(BillingInterval::Yearly))->toBe(990);
});

it('looks up the Stripe price for each interval', function () {
    expect(Plan::Pro->stripePrice())->toBe('price_pro')
        ->and(Plan::Pro->stripePrice(BillingInterval::Yearly))->toBe('price_pro_year')
        ->and(Plan::Business->stripePrice(BillingInterval::Yearly))->toBeNull();
});

it('maps a Stripe price id back to its plan, whichever interval it bills', function () {
    expect(Plan::fromStripePrice('price_pro'))->toBe(Plan::Pro)
        ->and(Plan::fromStripePrice('price_pro_year'))->toBe(Plan::Pro)
        ->and(Plan::fromStripePrice('price_biz'))->toBe(Plan::Business)
        ->and(Plan::fromStripePrice('price_other'))->toBeNull()
        ->and(Plan::fromStripePrice(null))->toBeNull();
});

it('maps a Stripe price id back to its interval', function () {
    expect(BillingInterval::fromStripePrice('price_pro'))->toBe(BillingInterval::Monthly)
        ->and(BillingInterval::fromStripePrice('price_pro_year'))->toBe(BillingInterval::Yearly)
        ->and(BillingInterval::fromStripePrice('price_other'))->toBeNull()
        ->and(BillingInterval::fromStripePrice(null))->toBeNull();
});

it('does not mistake an unset yearly price for a match', function () {
    config(['billing.plans.pro.stripe_price_yearly' => null]);

    expect(Plan::fromStripePrice(''))->toBeNull()->and(BillingInterval::fromStripePrice(null))->toBeNull();
});

it('only offers yearly billing for plans with a yearly price', function () {
    expect(Plan::Pro->hasYearlyPrice())->toBeTrue()->and(Plan::Free->hasYearlyPrice())->toBeFalse();

    config(['billing.plans.pro.yearly_price' => null]);
    expect(Plan::Pro->hasYearlyPrice())->toBeFalse();
});
