<?php

namespace App\Notifications;

/** Sent to a team's owner whenever its plan changes, whatever caused it (checkout, the portal, a webhook). */
class PlanChanged extends AppNotification
{
    public function __construct(private int $teamId, private string $teamName, private string $planLabel) {}

    protected function kind(): string
    {
        return 'plan.changed';
    }

    protected function params(): array
    {
        return ['team' => $this->teamName, 'plan' => $this->planLabel];
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
