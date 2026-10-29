<?php

/*
| Per-route rate limits (see AppServiceProvider::configureRateLimiting).
| Override in .env, e.g. to run the browser E2E suite repeatedly from one IP.
*/
return [
    'registration_per_hour' => (int) env('THROTTLE_REGISTRATION_PER_HOUR', 10),
    'invitations_per_minute' => (int) env('THROTTLE_INVITATIONS_PER_MINUTE', 5),
    // Requests a minute for each API token.
    'api_per_minute' => (int) env('THROTTLE_API_PER_MINUTE', 60),
];
