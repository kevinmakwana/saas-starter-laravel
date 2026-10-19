<?php

namespace App\Listeners;

use App\Mail\FailedJobAlertMail;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/** Emails ALERT_EMAIL when a queued job has failed for good (after its retries). Registered by event discovery. */
class SendFailedJobAlert
{
    public function handle(JobFailed $event): void
    {
        $to = config('alerts.failed_jobs.mail');
        if (! $to) {
            return;
        }

        try {
            $name = $event->job->resolveName();

            // Cache::add only succeeds for the first failure of this job class inside the window.
            $window = now()->addMinutes((int) config('alerts.failed_jobs.throttle_minutes'));
            if (! Cache::add('failed-job-alert:'.md5($name), true, $window)) {
                return;
            }

            Mail::to($to)->send(new FailedJobAlertMail(
                $name,
                $event->exception::class,
                Str::limit($event->exception->getMessage(), 500),
                $event->connectionName,
                $event->job->getQueue(),
            ));
        } catch (Throwable $e) {
            // An alert problem (mail or cache outage) must never fail the worker; the failure itself is already logged and stored.
            Log::warning('Could not send the failed job alert', ['exception' => $e]);
        }
    }
}
