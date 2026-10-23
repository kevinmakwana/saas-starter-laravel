<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Sent to the members of a team when its owner deletes it. It carries plain strings, not the team,
 * because the team no longer exists by the time the queued mail is built.
 */
class TeamDeletedMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $teamName, public string $deletedBy)
    {
        $this->onQueue('mail');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->teamName} was deleted");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.team-deleted', with: [
            'teamName' => $this->teamName,
            'deletedBy' => $this->deletedBy,
            'url' => route('dashboard'),
        ]);
    }
}
