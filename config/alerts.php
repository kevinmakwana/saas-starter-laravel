<?php

return [
    'failed_jobs' => [
        // Who gets an email when a queued job fails for good. Empty turns the alert off.
        'mail' => env('ALERT_EMAIL'),

        // At most one email per job class in this window, so a broken integration cannot flood the inbox.
        'throttle_minutes' => (int) env('ALERT_THROTTLE_MINUTES', 15),
    ],
];
