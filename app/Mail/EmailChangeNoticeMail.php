<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Sent to the OLD address, so a change nobody there asked for does not go unnoticed: once when it is requested, once when it is done. */
class EmailChangeNoticeMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $name, public string $newEmail, public bool $completed = false)
    {
        $this->onQueue('mail');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->completed ? __('Your email address was changed') : __('Someone asked to change your email address'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.email-change-notice', with: [
            'name' => $this->name,
            'newEmail' => $this->newEmail,
            'completed' => $this->completed,
            'url' => route('profile.edit'),
        ]);
    }
}
