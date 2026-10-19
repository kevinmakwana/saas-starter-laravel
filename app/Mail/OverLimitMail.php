<?php

namespace App\Mail;

use App\Models\Team;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Sent to the owner when a plan change leaves the team with more projects or members than the new plan includes. */
class OverLimitMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** @param  list<array{key: string, label: string, used: int, limit: int}>  $overages */
    public function __construct(public Team $team, public array $overages)
    {
        $this->onQueue('mail');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->team->name} is over the limits of its plan");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.over-limit', with: [
            'team' => $this->team,
            'plan' => $this->team->currentPlan(),
            'overages' => $this->overages,
            'url' => route('billing.show'),
        ]);
    }
}
