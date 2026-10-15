<?php

/*
| Plans, limits and feature flags. `null` limit = unlimited.
| Stripe price ids are only needed when BILLING_GATEWAY=stripe.
*/
return [
    'gateway' => env('BILLING_GATEWAY', 'fake'),

    'webhook' => [
        // The local fallback secret is only used by the fake gateway; with Stripe, an empty secret rejects every webhook.
        'secret' => env('STRIPE_WEBHOOK_SECRET') ?: (env('BILLING_GATEWAY', 'fake') === 'stripe' ? '' : 'whsec_local_fake_secret'),
        'tolerance' => 300,
        // Stored events still unprocessed after this many minutes are re-dispatched by `webhooks:retry-stuck`,
        // but only for this many days (older ones need a human and `webhooks:replay`).
        'retry_after_minutes' => 10,
        'retry_window_days' => 7,
    ],

    // Days a team keeps its plan after a failed payment before dropping to Free.
    'grace_days' => 7,

    'plans' => [
        'free' => [
            'name' => 'Free',
            'price' => 0,
            'stripe_price' => null,
            'limits' => ['projects' => 3, 'members' => 2],
        ],
        'pro' => [
            'name' => 'Pro',
            'price' => 29,
            'stripe_price' => env('STRIPE_PRICE_PRO'),
            'limits' => ['projects' => 25, 'members' => 10],
        ],
        'business' => [
            'name' => 'Business',
            'price' => 99,
            'stripe_price' => env('STRIPE_PRICE_BUSINESS'),
            'limits' => ['projects' => null, 'members' => null],
        ],
    ],

    // feature => plans that include it
    'features' => [
        'audit_log' => ['business'],
        'task_export' => ['pro', 'business'],
    ],
];
