<?php

/*
| How long old rows are kept before the daily `model:prune` run deletes them.
| An audit log retention of null (or 0) keeps entries forever.
*/
return [
    // Invitations are pruned this many days after they expired.
    'invitations_days' => (int) env('RETENTION_INVITATIONS_DAYS', 30),

    // Only webhook events that were processed are pruned; unprocessed ones are kept for the retry sweeper.
    'webhook_events_days' => (int) env('RETENTION_WEBHOOK_EVENTS_DAYS', 30),

    'audit_logs_days' => env('RETENTION_AUDIT_LOGS_DAYS') ? (int) env('RETENTION_AUDIT_LOGS_DAYS') : null,
];
