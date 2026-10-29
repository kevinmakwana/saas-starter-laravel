<?php

namespace App\Notifications;

/** Sent to a team's owner shortly before a free trial turns into a paid plan. */
class TrialEnding extends AppNotification
{
    public function __construct(private int $teamId, private string $teamName, private string $endsOn) {}

    protected function kind(): string
    {
        return 'trial.ending';
    }

    protected function params(): array
    {
        return ['team' => $this->teamName, 'date' => $this->endsOn];
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
