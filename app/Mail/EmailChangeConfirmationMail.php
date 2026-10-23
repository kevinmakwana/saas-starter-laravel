<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Sent to the NEW address: opening the link proves it is theirs and completes the change. */
class EmailChangeConfirmationMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $name, public string $url)
    {
        $this->onQueue('mail');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Confirm your new email address');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.email-change-confirmation', with: [
            'name' => $this->name,
            'url' => $this->url,
            'minutes' => User::EMAIL_CHANGE_TTL_MINUTES,
        ]);
    }
}
