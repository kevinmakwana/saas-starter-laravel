<?php

namespace App\Mail;

use App\Models\Team;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class TrialEndingMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Team $team, public Carbon $endsAt)
    {
        $this->onQueue('mail');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Your {$this->team->name} trial ends on {$this->endsAt->toFormattedDateString()}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.trial-ending', with: [
            'team' => $this->team,
            'plan' => $this->team->currentPlan(),
            'endsAt' => $this->endsAt,
            'url' => route('billing.show'),
        ]);
    }
}
