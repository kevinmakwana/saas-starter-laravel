<?php

use App\Listeners\SendFailedJobAlert;
use App\Mail\FailedJobAlertMail;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

class AlwaysFailsJob implements ShouldQueue
{
    use Dispatchable;

    public function __construct(public string $secret = 'card-4242') {}

    public function handle(): void
    {
        throw new RuntimeException('Gateway exploded');
    }
}

class AlsoFailsJob implements ShouldQueue
{
    use Dispatchable;

    public function handle(): void
    {
        throw new LogicException('Different problem');
    }
}

/** Runs a job on the sync queue, which fires JobFailed like a worker does, and swallows the rethrown exception. */
function failJob(object $job): void
{
    try {
        dispatch($job);
    } catch (Throwable) {
    }
}

beforeEach(function () {
    Mail::fake();
    Cache::flush();
    config(['alerts.failed_jobs.mail' => 'ops@example.com', 'alerts.failed_jobs.throttle_minutes' => 15]);
});

it('emails the alert address when a queued job fails', function () {
    failJob(new AlwaysFailsJob);

    Mail::assertSent(FailedJobAlertMail::class, fn (FailedJobAlertMail $mail) => $mail->hasTo('ops@example.com')
        && $mail->jobName === AlwaysFailsJob::class
        && $mail->exceptionClass === RuntimeException::class
        && $mail->exceptionMessage === 'Gateway exploded');
});

it('sends nothing when no alert address is configured', function () {
    config(['alerts.failed_jobs.mail' => null]);

    failJob(new AlwaysFailsJob);

    Mail::assertNothingSent();
});

it('sends one alert per job class per throttle window', function () {
    failJob(new AlwaysFailsJob);
    failJob(new AlwaysFailsJob);
    failJob(new AlsoFailsJob);

    Mail::assertSentCount(2);
});

it('alerts again once the throttle window has passed', function () {
    failJob(new AlwaysFailsJob);

    $this->travel(16)->minutes();
    failJob(new AlwaysFailsJob);

    Mail::assertSentCount(2);
});

it('never puts the job payload in the email', function () {
    failJob(new AlwaysFailsJob('card-4242'));

    Mail::assertSent(FailedJobAlertMail::class, fn (FailedJobAlertMail $mail) => ! str_contains($mail->render(), 'card-4242'));
});

it('does not break the worker if the alert itself cannot be sent', function () {
    Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP down'));

    expect(fn () => failJob(new AlwaysFailsJob))->not->toThrow(Throwable::class);
});

it('survives a cache outage while deciding whether to alert', function () {
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('resolveName')->andReturn('App\\Jobs\\Anything');
    $job->shouldReceive('getQueue')->andReturn('default');
    Cache::shouldReceive('add')->andThrow(new RuntimeException('Redis is down'));

    $handle = fn () => (new SendFailedJobAlert)->handle(new JobFailed('redis', $job, new RuntimeException('boom')));

    expect($handle)->not->toThrow(Throwable::class);
});
