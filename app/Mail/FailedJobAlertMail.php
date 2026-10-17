<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Sent straight away, never queued: a queued alert could fail on the same broken queue it reports on.
 * It carries the job class and the exception only, never the job payload (which can hold customer data).
 */
class FailedJobAlertMail extends Mailable
{
    public function __construct(
        public string $jobName,
        public string $exceptionClass,
        public string $exceptionMessage,
        public string $connection,
        public string $queue,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '['.config('app.name')."] Queued job failed: {$this->jobName}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.failed-job-alert');
    }
}
