<?php

/*
| Files attached to tasks. They live on a private disk (never served by URL) under attachments/{team id}/,
| and are only ever downloaded through an authorized request. Point ATTACHMENTS_DISK at an S3 disk for production.
*/
return [
    'disk' => env('ATTACHMENTS_DISK', 'local'),

    // Largest single file, in kilobytes. Each plan also has a total storage quota (billing.plans.*.limits.storage_mb).
    'max_file_kb' => (int) env('ATTACHMENTS_MAX_FILE_KB', 10240),

    // Allowed by name and by what the file really contains. No SVG or HTML: a browser could run them.
    'extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'txt', 'csv', 'md', 'docx', 'xlsx', 'pptx'],
];
