<?php

/*
| HTTP security headers, applied to every web response by App\Http\Middleware\SecurityHeaders.
| The Content-Security-Policy is built there (it needs a per-request nonce); set CSP_REPORT_ONLY=true to try
| a stricter change without blocking anything.
*/
return [
    'csp' => [
        'enabled' => (bool) env('CSP_ENABLED', true),
        'report_only' => (bool) env('CSP_REPORT_ONLY', false),

        // Extra sources for third parties you add later, per directive: ['script-src' => ['https://js.example.com']].
        'extra' => [],
    ],

    // Only sent over https. Turn it off for a host that must stay reachable over plain http.
    'hsts' => [
        'enabled' => (bool) env('HSTS_ENABLED', true),
        'max_age' => 31536000,
    ],
];
