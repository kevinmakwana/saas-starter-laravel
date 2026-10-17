<x-mail::message>
# A queued job failed

**Job:** {{ $jobName }}<br>
**Queue:** {{ $connection }} / {{ $queue }}<br>
**Exception:** {{ $exceptionClass }}

> {{ $exceptionMessage }}

It ran out of retries and is in the failed jobs table. Inspect it with `php artisan queue:failed` and retry it with
`php artisan queue:retry <id>` once the cause is fixed.

You get at most one email per job class every {{ config('alerts.failed_jobs.throttle_minutes') }} minutes.
</x-mail::message>
