<?php

use App\Enums\BillingInterval;
use App\Enums\Plan;

it('reads the label and monthly price from config', function () {
    expect(Plan::Pro->label())->toBe('Pro')
        ->and(Plan::Free->price())->toBe(0)
        ->and(Plan::Pro->price())->toBe(29)
        ->and(Plan::Business->price(BillingInterval::Monthly))->toBe(99);
});

it('prices yearly billing separately and only offers it where configured', function () {
    expect(Plan::Pro->price(BillingInterval::Yearly))->toBe(290)
        ->and(Plan::Pro->hasYearlyPrice())->toBeTrue()
        ->and(Plan::Free->hasYearlyPrice())->toBeFalse();
});

it('ranks plans from cheapest to most capable', function () {
    expect(Plan::Free->rank())->toBe(0)
        ->and(Plan::Pro->rank())->toBe(1)
        ->and(Plan::Business->rank())->toBe(2);
});

it('treats a null limit as unlimited', function () {
    expect(Plan::Free->limit('projects'))->toBe(3)
        ->and(Plan::Pro->limit('members'))->toBe(10)
        ->and(Plan::Business->limit('projects'))->toBeNull()
        ->and(Plan::Pro->limit('unknown'))->toBeNull();
});

it('knows which plans include a feature', function () {
    expect(Plan::Business->includes('audit_log'))->toBeTrue()
        ->and(Plan::Pro->includes('audit_log'))->toBeFalse()
        ->and(Plan::Pro->includes('task_export'))->toBeTrue()
        ->and(Plan::Free->includes('task_export'))->toBeFalse()
        ->and(Plan::Business->includes('no_such_feature'))->toBeFalse();
});

it('returns a configured Stripe price id and null for a blank one', function () {
    config(['billing.plans.pro.stripe_price' => 'price_pro', 'billing.plans.pro.stripe_price_yearly' => '']);

    expect(Plan::Pro->stripePrice())->toBe('price_pro')
        ->and(Plan::Pro->stripePrice(BillingInterval::Yearly))->toBeNull()
        ->and(Plan::Free->stripePrice())->toBeNull();
});

it('maps a Stripe price id of either interval back to its plan', function () {
    config([
        'billing.plans.pro.stripe_price' => 'price_pro_m',
        'billing.plans.pro.stripe_price_yearly' => 'price_pro_y',
        'billing.plans.business.stripe_price' => 'price_biz_m',
    ]);

    expect(Plan::fromStripePrice('price_pro_m'))->toBe(Plan::Pro)
        ->and(Plan::fromStripePrice('price_pro_y'))->toBe(Plan::Pro)
        ->and(Plan::fromStripePrice('price_biz_m'))->toBe(Plan::Business)
        ->and(Plan::fromStripePrice('price_other'))->toBeNull()
        ->and(Plan::fromStripePrice(''))->toBeNull()
        ->and(Plan::fromStripePrice(null))->toBeNull();
});

it('suggests the cheapest higher plan with room, for upgrade prompts', function () {
    expect(Plan::Free->nextWithRoomFor('projects', 4))->toBe(Plan::Pro)
        ->and(Plan::Free->nextWithRoomFor('projects', 26))->toBe(Plan::Business)
        ->and(Plan::Pro->nextWithRoomFor('members', 11))->toBe(Plan::Business)
        ->and(Plan::Business->nextWithRoomFor('projects', 1000))->toBeNull();
});

it('never suggests its own or a lower plan', function () {
    config(['billing.plans.business.limits.projects' => 5]);

    expect(Plan::Business->nextWithRoomFor('projects', 6))->toBeNull()
        ->and(Plan::Pro->nextWithRoomFor('projects', 26))->toBeNull();
});
