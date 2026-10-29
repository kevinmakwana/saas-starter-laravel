<?php

namespace App\Mail;

use App\Models\TeamInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TeamInvitationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public TeamInvitation $invitation)
    {
        $this->onQueue('mail');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __("You're invited to join :team on Acme", ['team' => $this->invitation->team->name]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.team-invitation', with: [
            'team' => $this->invitation->team,
            'inviter' => $this->invitation->inviter,
            'role' => $this->invitation->role,
            'url' => $this->invitation->acceptUrl(),
        ]);
    }
}
