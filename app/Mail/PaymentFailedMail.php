<?php

namespace App\Mail;

use App\Models\Team;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PaymentFailedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Team $team)
    {
        $this->onQueue('mail');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Action needed: payment failed for :team', ['team' => $this->team->name]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.payment-failed', with: [
            'team' => $this->team,
            'plan' => $this->team->currentPlan(),
            'deadline' => $this->team->payment_grace_ends_at,
            'url' => route('billing.portal', ['team' => $this->team->getKey()]),
        ]);
    }
}
