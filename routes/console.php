<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Deletes expired invitations, processed webhook events and (when configured) old audit logs. See config/retention.php.
Schedule::command('model:prune')->daily();
