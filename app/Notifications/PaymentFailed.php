<?php

namespace App\Notifications;

/** Sent to a team's owner when a payment fails and the grace period starts. */
class PaymentFailed extends AppNotification
{
    public function __construct(private int $teamId, private string $teamName) {}

    protected function kind(): string
    {
        return 'payment.failed';
    }

    protected function params(): array
    {
        return ['team' => $this->teamName];
    }

    protected function teamId(): ?int
    {
        return $this->teamId;
    }

    protected function url(): string
    {
        return '/billing';
    }
}
